<?php

use Blemli\Swissstreets\Import\Downloader;
use Blemli\Swissstreets\Import\Importer;
use Blemli\Swissstreets\Models\Address;
use Blemli\Swissstreets\Tests\Fixtures\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

it('imports official, real, decimal-free addresses only', function () {
    $result = importFixture();

    expect($result->unchanged)->toBeFalse()
        ->and($result->initial)->toBeTrue()
        ->and($result->total)->toBe(7)
        ->and($result->added)->toBe(7)
        ->and($result->skipped)->toBe(3)
        ->and(Address::count())->toBe(7);

    // 65.1 (decimal), planned, unofficial: skipped
    expect(Address::find(102434738))->toBeNull()
        ->and(Address::find(100265201))->toBeNull()
        ->and(Address::find(100265202))->toBeNull();

    $spalenring = Address::find(100297441);

    expect($spalenring->egid)->toBe(441400)
        ->and($spalenring->street)->toBe('Spalenring')
        ->and($spalenring->number)->toBe('113')
        ->and($spalenring->number_int)->toBe(113)
        ->and($spalenring->zip)->toBe(4055)
        ->and($spalenring->locality)->toBe('Basel')
        ->and($spalenring->commune)->toBe('Basel')
        ->and($spalenring->canton)->toBe('BS')
        ->and($spalenring->category)->toBe('residential')
        ->and($spalenring->lat)->toEqualWithDelta(47.5565, 0.001)
        ->and($spalenring->lng)->toEqualWithDelta(7.5757, 0.001)
        ->and($spalenring->line)->toBe('Spalenring 113, 4055 Basel')
        ->and((string) $spalenring)->toBe('Spalenring 113, 4055 Basel')
        ->and($spalenring->modified_at?->toDateString())->toBe('2024-11-15');

    // quoted field with a semicolon inside is parsed, empty number becomes null
    expect(Address::find(300000001)->number)->toBeNull()
        ->and(Address::find(300000001)->street_line)->toBe('Dorfstrasse');
});

it('keeps swissgrid coordinates only when configured', function () {
    // The fixture panel registers the plugin with ->swissgrid().
    expect(config('swissstreets-for-filament.swissgrid'))->toBeTrue();

    importFixture();

    expect(Address::find(100297441)->easting)->toEqualWithDelta(2610314.694, 0.001)
        ->and(Address::find(100297441)->northing)->toEqualWithDelta(1267321.697, 0.001);

    config()->set('swissstreets-for-filament.swissgrid', false);
    app(Importer::class)->run(fixturePath('register.csv'), force: true);

    expect(Address::find(100297441)->easting)->toBeNull();
});

it('restricts the import to the configured cantons', function () {
    config()->set('swissstreets-for-filament.cantons', ['zh']);

    $result = importFixture();

    expect($result->total)->toBe(2)
        ->and(Address::pluck('canton')->unique()->all())->toBe(['ZH']);
});

it('imports unofficial and planned addresses when enabled', function () {
    config()->set('swissstreets-for-filament.unofficial', true);
    config()->set('swissstreets-for-filament.planned', true);

    expect(importFixture()->total)->toBe(9);
});

it('soft deletes addresses that vanished and logs the change', function () {
    importFixture();

    // A real single-file channel instead of a Log facade mock — Laravel
    // itself logs deprecations through the facade on older versions.
    $logPath = sys_get_temp_dir() . '/swissstreets-test.log';
    File::delete($logPath);
    config()->set('logging.channels.swissstreets_test', ['driver' => 'single', 'path' => $logPath]);
    config()->set('swissstreets-for-filament.log_channel', 'swissstreets_test');

    $csv = str_replace(
        '200000002;20000001;900002;0;Bahnhofstrasse;3;',
        '200000003;20000001;900003;0;Bahnhofstrasse;5;',
        file_get_contents(fixturePath('register.csv')),
    );
    $path = sys_get_temp_dir() . '/swissstreets-second.csv';
    file_put_contents($path, $csv);

    $result = app(Importer::class)->run($path);
    File::delete($path);

    $log = File::get($logPath);
    File::delete($logPath);

    expect($log)->toContain('removed address 200000002 — Bahnhofstrasse 3, 8001 Zürich')
        ->toContain('added address 200000003 — Bahnhofstrasse 5, 8001 Zürich');

    expect($result->added)->toBe(1)
        ->and($result->removed)->toBe(1)
        ->and($result->restored)->toBe(0)
        ->and(Address::find(200000002))->toBeNull()
        ->and(Address::withTrashed()->find(200000002)->trashed())->toBeTrue()
        ->and(Address::find(200000003)->line)->toBe('Bahnhofstrasse 5, 8001 Zürich');

    $removed = Activity::query()->where('event', 'removed')->get();

    expect($removed)->toHaveCount(1)
        ->and($removed->first()->description)->toBe('System removed address')
        ->and((int) $removed->first()->subject_id)->toBe(200000002)
        ->and($removed->first()->properties['address'])->toBe('Bahnhofstrasse 3, 8001 Zürich')
        ->and(Activity::query()->where('event', 'added')->where('subject_id', 200000003)->count())->toBe(1);
});

it('restores an address that reappears', function () {
    importFixture();
    Address::find(200000002)->delete();

    $result = app(Importer::class)->run(fixturePath('register.csv'));

    expect($result->restored)->toBe(1)
        ->and(Address::find(200000002)->trashed())->toBeFalse();
});

it('refuses to wipe the table when the file is empty', function () {
    importFixture();

    $path = sys_get_temp_dir() . '/swissstreets-empty.csv';
    file_put_contents($path, "ADR_EGAID;STN_LABEL\n");

    expect(fn () => app(Importer::class)->run($path))->toThrow(RuntimeException::class);
    File::delete($path);

    expect(Address::count())->toBe(7);
});

it('reads straight out of the zip', function () {
    $zipPath = sys_get_temp_dir() . '/swissstreets-fixture.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile(fixturePath('register.csv'), 'amtliches-gebaeudeadressverzeichnis_ch_2056.csv');
    $zip->close();

    expect(app(Importer::class)->run($zipPath)->total)->toBe(7);
    File::delete($zipPath);
})->skip(fn () => ! class_exists(ZipArchive::class), 'ext-zip missing');

it('skips the download when the remote file is unchanged', function () {
    importFixture();
    Cache::forever(Downloader::VERSION_CACHE_KEY, 'Fri, 12 Sep 2026 05:48:00 GMT');

    Http::fake([
        '*' => Http::response('', 200, ['Last-Modified' => 'Fri, 12 Sep 2026 05:48:00 GMT']),
    ]);

    $result = app(Importer::class)->run();

    expect($result->unchanged)->toBeTrue();
    Http::assertSentCount(1);
});

it('downloads and imports when the remote file changed', function () {
    importFixture();
    Cache::forever(Downloader::VERSION_CACHE_KEY, 'old');

    Http::fake(function ($request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, ['Last-Modified' => 'new']);
        }

        return Http::response(file_get_contents(fixturePath('register.csv')), 200);
    });

    // The downloader writes the body to disk via Guzzle's sink; with Http::fake
    // nothing lands there, so hand the importer a ready file instead.
    $downloader = Mockery::mock(Downloader::class)->makePartial();
    $downloader->shouldReceive('download')->once()->andReturn(fixturePath('register.csv'));
    app()->instance(Downloader::class, $downloader);

    $result = app(Importer::class)->run();

    expect($result->unchanged)->toBeFalse()
        ->and(Cache::get(Downloader::VERSION_CACHE_KEY))->toBe('new');
});

it('sends a summary notification to the configured users', function () {
    $user = User::create(['name' => 'Admin', 'email' => 'a@example.com', 'password' => 'x']);
    bootPanel();

    importFixture();

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toBe('Address import finished');
});

it('runs through the artisan command', function () {
    $this->artisan('swissstreets:import', ['--file' => fixturePath('register.csv')])
        ->expectsOutputToContain('Initial import')
        ->assertSuccessful();

    expect(Address::count())->toBe(7);
});

it('fails the artisan command for a missing file', function () {
    $this->artisan('swissstreets:import', ['--file' => '/nope.csv'])->assertFailed();
});

it('registers the nightly schedule', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'swissstreets:import'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 3 * * *');
});

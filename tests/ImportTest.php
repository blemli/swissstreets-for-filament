<?php

use Blemli\Swissstreets\Events\AddressAdded;
use Blemli\Swissstreets\Events\AddressRemoved;
use Blemli\Swissstreets\Events\AddressRestored;
use Blemli\Swissstreets\Events\ImportFinished;
use Blemli\Swissstreets\Import\CsvReader;
use Blemli\Swissstreets\Import\Downloader;
use Blemli\Swissstreets\Import\Importer;
use Blemli\Swissstreets\Import\ImportFailed;
use Blemli\Swissstreets\Import\ImportLock;
use Blemli\Swissstreets\Models\Address;
use Blemli\Swissstreets\Tests\Fixtures\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;

it('imports official, real, decimal-free addresses only', function () {
    $result = importFixture();

    expect($result->unchanged)->toBeFalse()
        ->and($result->initial)->toBeTrue()
        ->and($result->total)->toBe(8)
        ->and($result->added)->toBe(8)
        ->and($result->skipped)->toBe(3)
        ->and(Address::count())->toBe(8);

    // 65.1 (decimal), planned, unofficial: skipped
    expect(Address::find(102434738))->toBeNull()
        ->and(Address::find(100265201))->toBeNull()
        ->and(Address::find(100265202))->toBeNull();

    $spalenring = Address::find(100297441);

    expect($spalenring->egid)->toBe(441400)
        ->and($spalenring->street)->toBe('Spalenring')
        ->and($spalenring->number)->toBe('113')
        ->and($spalenring->number_int)->toBe(113)
        ->and($spalenring->zip)->toBe('4055')
        ->and($spalenring->locality)->toBe('Basel')
        ->and($spalenring->commune)->toBe('Basel')
        ->and($spalenring->canton)->toBe('BS')
        ->and($spalenring->category)->toBe('residential')
        ->and($spalenring->lat)->toEqualWithDelta(47.5565, 0.001)
        ->and($spalenring->lng)->toEqualWithDelta(7.5757, 0.001)
        ->and($spalenring->line)->toBe('Spalenring 113, 4055 Basel')
        ->and((string) $spalenring)->toBe('Spalenring 113, 4055 Basel')
        ->and($spalenring->modified_at?->toDateString())->toBe('2024-11-15');

    // normalised search keys: lowercase, accents folded
    expect(Address::find(400000001)->street_search)->toBe("rue de l'eglise")
        ->and(Address::find(400000001)->locality_search)->toBe('ecublens vd')
        ->and(Address::find(200000001)->locality_search)->toBe('zurich');

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

    expect(importFixture()->total)->toBe(10);
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

    expect(Address::count())->toBe(8);
});

it('reads straight out of the zip', function () {
    $zipPath = sys_get_temp_dir() . '/swissstreets-fixture.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile(fixturePath('register.csv'), 'amtliches-gebaeudeadressverzeichnis_ch_2056.csv');
    $zip->close();

    expect(app(Importer::class)->run($zipPath)->total)->toBe(8);
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

it('stays quiet when the register is unchanged', function () {
    $user = User::create(['name' => 'Admin', 'email' => 'b@example.com', 'password' => 'x']);
    bootPanel();
    importFixture();
    Cache::forever(Downloader::VERSION_CACHE_KEY, 'same');
    Http::fake(['*' => Http::response('', 200, ['Last-Modified' => 'same'])]);

    app(Importer::class)->run();

    expect($user->notifications()->count())->toBe(1);
});

it('runs through the artisan command', function () {
    $this->artisan('swissstreets:import', ['--file' => fixturePath('register.csv')])
        ->expectsOutputToContain('Initial import')
        ->assertSuccessful();

    expect(Address::count())->toBe(8);
});

it('fails the artisan command for a missing file', function () {
    $this->artisan('swissstreets:import', ['--file' => '/nope.csv'])->assertFailed();
});

it('does not register a schedule on its own', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'swissstreets:import'));

    expect($events)->toHaveCount(0);
});

it('never removes manually added addresses on import', function () {
    importFixture();
    $berlin = Address::createManual(['street' => 'Musterweg', 'number' => '7', 'zip' => '12345', 'locality' => 'Berlin', 'country' => 'de']);

    $result = app(Importer::class)->run(fixturePath('register.csv'), force: true);

    expect($result->removed)->toBe(0)
        ->and($berlin->fresh()->trashed())->toBeFalse()
        ->and($berlin->fresh()->source)->toBe(Address::SOURCE_MANUAL);
});

it('dispatches events for added, removed and restored addresses and for the run', function () {
    importFixture();
    Address::find(100265200)->delete();

    Event::fake([
        AddressAdded::class,
        AddressRemoved::class,
        AddressRestored::class,
        ImportFinished::class,
    ]);

    $csv = str_replace(
        '200000002;20000001;900002;0;Bahnhofstrasse;3;',
        '200000003;20000001;900003;0;Bahnhofstrasse;5;',
        file_get_contents(fixturePath('register.csv')),
    );
    $path = sys_get_temp_dir() . '/swissstreets-events.csv';
    file_put_contents($path, $csv);
    $result = app(Importer::class)->run($path);
    File::delete($path);

    Event::assertDispatched(AddressAdded::class, fn ($e) => $e->address->egaid === 200000003);
    Event::assertDispatched(AddressRemoved::class, fn ($e) => $e->address->egaid === 200000002);
    Event::assertDispatched(AddressRestored::class, fn ($e) => $e->address->egaid === 100265200);
    Event::assertDispatched(ImportFinished::class, fn ($e) => $e->result === $result && $e->result->added === 1);
    Event::assertDispatchedTimes(AddressAdded::class, 1);
});

it('dispatches AddressAdded for manual addresses', function () {
    Event::fake([AddressAdded::class]);

    $berlin = Address::createManual(['street' => 'Musterweg', 'zip' => '12345', 'locality' => 'Berlin', 'country' => 'DE']);

    Event::assertDispatched(AddressAdded::class, fn ($e) => $e->address->is($berlin));
});

// --- transient network errors (docs/task/2026-09-16-import-connection-reset.md)

it('retries a reset version probe before trusting the answer', function () {
    Sleep::fake();
    importFixture();
    Cache::forever(Downloader::VERSION_CACHE_KEY, 'same');
    Http::fakeSequence()
        ->pushFailedConnection('cURL error 35: Recv failure: Connection reset by peer (see https://curl.se/libcurl/c/libcurl-errors.html) for https://data.geo.admin.ch/x.zip')
        ->push('', 200, ['Last-Modified' => 'same']);

    expect(app(Importer::class)->run()->unchanged)->toBeTrue();

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

it('imports even when the version probe never reaches swisstopo', function () {
    Sleep::fake();
    importFixture();
    Cache::forever(Downloader::VERSION_CACHE_KEY, 'old');
    Http::fake(['*' => Http::failedConnection()]);

    $downloader = Mockery::mock(Downloader::class)->makePartial();
    $downloader->shouldReceive('download')->once()->andReturn(fixturePath('register.csv'));
    app()->instance(Downloader::class, $downloader);

    $result = app(Importer::class)->run();

    expect($result->unchanged)->toBeFalse()
        ->and(Cache::get(Downloader::VERSION_CACHE_KEY))->toBe('old');
    Http::assertSentCount(1 + count(Downloader::BACKOFF_MS));
});

it('leaves no half-written download behind when swisstopo keeps resetting', function () {
    Sleep::fake();
    Http::fake(['*' => Http::failedConnection('cURL error 35: Recv failure: Connection reset by peer (see https://curl.se/libcurl/c/libcurl-errors.html) for https://data.geo.admin.ch/x.zip')]);

    $part = app(Downloader::class)->zipPath() . '.part';
    File::ensureDirectoryExists(dirname($part));
    File::put($part, 'half');

    try {
        app(Downloader::class)->download();
        $this->fail('download() should have thrown');
    } catch (ImportFailed $e) {
        expect($e->getMessage())->toBe('Could not reach swisstopo (data.geo.admin.ch): Recv failure: Connection reset by peer. Check the network and run again: php artisan swissstreets:import');
    }

    expect(File::exists($part))->toBeFalse();
    Http::assertSentCount(1 + count(Downloader::BACKOFF_MS));
});

it('does not retry an HTTP error answer', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response('', 503)]);

    expect(fn () => app(Downloader::class)->download())->toThrow(ImportFailed::class, 'swisstopo answered HTTP 503');
    Http::assertSentCount(1);
});

it('tells the operator what to do when swisstopo is unreachable', function () {
    Sleep::fake();
    Http::fake(['*' => Http::failedConnection()]);

    // One expectsOutputToContain() per written line only — so read the buffer instead.
    expect(Artisan::call('swissstreets:import'))->toBe(1)
        ->and(Artisan::output())
        ->toContain('Could not reach swisstopo (data.geo.admin.ch)')
        ->toContain('run again: php artisan swissstreets:import')
        ->not->toContain('cURL error');
});

// --- stale lock (docs/task/2026-09-16-import-stale-lock.md)

it('refuses to run beside another import and says how to unlock', function () {
    holdImportLock(['pid' => 4711, 'host' => 'elsewhere']);

    expect(Artisan::call('swissstreets:import', ['--file' => fixturePath('register.csv')]))->toBe(1)
        ->and(Artisan::output())
        ->toContain('running since 2026-09-16 14:03')
        ->toContain('PID 4711 on elsewhere')
        ->toContain('started from the command line')
        ->toContain('php artisan swissstreets:import --unlock')
        ->toContain('schedule:clear-cache');

    expect(Address::count())->toBe(0)
        ->and((new ImportLock)->isLocked())->toBeTrue();
});

it('releases a stale lock with --unlock and imports', function () {
    holdImportLock(['pid' => 4711, 'host' => 'elsewhere']);

    $this->artisan('swissstreets:import', ['--file' => fixturePath('register.csv'), '--unlock' => true])
        ->assertSuccessful();

    expect(Address::count())->toBe(8)
        ->and((new ImportLock)->isLocked())->toBeFalse()
        ->and((new ImportLock)->metadata())->toBeNull();
});

it('also clears the scheduler overlap mutex on --unlock', function () {
    $event = app(Schedule::class)->command('swissstreets:import')->dailyAt('03:00')->withoutOverlapping(120);
    $event->mutex->create($event);
    expect($event->mutex->exists($event))->toBeTrue();

    $this->artisan('swissstreets:import', ['--file' => fixturePath('register.csv'), '--unlock' => true])
        ->assertSuccessful();

    expect($event->mutex->exists($event))->toBeFalse();
});

it('heals a lock whose process died on this host', function () {
    $pid = 4194000;
    while (posix_kill($pid, 0)) {
        $pid--;
    }
    holdImportLock(['pid' => $pid, 'host' => gethostname()]);

    $this->artisan('swissstreets:import', ['--file' => fixturePath('register.csv')])
        ->assertSuccessful();

    expect(Address::count())->toBe(8)
        ->and((new ImportLock)->isLocked())->toBeFalse();
})->skip(fn () => ! function_exists('posix_kill'), 'ext-posix missing');

it('keeps a lock whose process is alive on this host', function () {
    holdImportLock(['pid' => getmypid(), 'host' => gethostname()]);

    $this->artisan('swissstreets:import', ['--file' => fixturePath('register.csv')])
        ->expectsOutputToContain('PID ' . getmypid())
        ->assertFailed();

    expect(Address::count())->toBe(0);
})->skip(fn () => ! function_exists('posix_kill'), 'ext-posix missing');

it('frees the lock and the half download when aborted by a signal', function () {
    $lock = new ImportLock;
    expect($lock->acquire())->toBeTrue();

    $part = app(Downloader::class)->zipPath() . '.part';
    File::ensureDirectoryExists(dirname($part));
    File::put($part, 'half');

    (new Importer(app(Downloader::class), app(CsvReader::class), $lock))->abort();

    expect((new ImportLock)->isLocked())->toBeFalse()
        ->and((new ImportLock)->metadata())->toBeNull()
        ->and(File::exists($part))->toBeFalse();
});

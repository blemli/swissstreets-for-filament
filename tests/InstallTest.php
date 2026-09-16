<?php

use Blemli\Swissstreets\Import\Downloader;
use Blemli\Swissstreets\Models\Address;
use Blemli\Swissstreets\Support\ScheduleInstaller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

beforeEach(function () {
    // The fixture panel switches health on; the installer only asks while it is off.
    config()->set('swissstreets-for-filament.health.enabled', false);
});

it('asks for a time and writes the nightly import into routes/console.php', function () {
    $this->artisan('swissstreets:install')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '04:15')
        ->expectsOutputToContain('Created routes/console.php with the nightly import at 04:15.')
        ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'no')
        ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'no')
        ->expectsOutputToContain('Later then: php artisan swissstreets:import')
        ->assertSuccessful();

    $contents = File::get(base_path('routes/console.php'));

    expect($contents)->toStartWith("<?php\n")
        ->toContain(ScheduleInstaller::MARKER)
        ->toContain("Schedule::command('swissstreets:import')")
        ->toContain("->dailyAt('04:15')")
        ->and((new ScheduleInstaller)->isInstalled())->toBeTrue();
});

it('appends to an existing routes/console.php and never duplicates', function () {
    File::ensureDirectoryExists(base_path('routes'));
    File::put(base_path('routes/console.php'), "<?php\n\nSchedule::command('inspire')->hourly();\n");

    $installer = new ScheduleInstaller;

    expect($installer->install('03:00'))->toBe('added')
        ->and($installer->install('05:00'))->toBe('exists');

    $contents = File::get(base_path('routes/console.php'));

    expect($contents)->toContain("Schedule::command('inspire')->hourly();")
        ->and(substr_count($contents, 'swissstreets:import'))->toBe(1)
        ->and($contents)->toContain("->dailyAt('03:00')")
        ->not->toContain("->dailyAt('05:00')");
});

it('skips the schedule on an empty answer and prints the snippet', function () {
    $this->artisan('swissstreets:install')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
        ->expectsOutputToContain('Skipped. Add it yourself when you are ready:')
        ->expectsOutputToContain("->dailyAt('" . ScheduleInstaller::defaultTime() . "')")
        ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'no')
        ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'no')
        ->assertSuccessful();

    expect(File::exists(base_path('routes/console.php')))->toBeFalse();
});

it('rejects a malformed time until a valid one is given', function () {
    $this->artisan('swissstreets:install')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '3 am')
        ->expectsQuestion('Please enter a time as HH:MM (empty to skip)', '23:59')
        ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'no')
        ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'no')
        ->assertSuccessful();

    expect(File::get(base_path('routes/console.php')))->toContain("->dailyAt('23:59')");
});

it('removes exactly its own block on uninstall', function () {
    File::ensureDirectoryExists(base_path('routes'));
    File::put(base_path('routes/console.php'), "<?php\n\nSchedule::command('inspire')->hourly();\n");
    (new ScheduleInstaller)->install('03:00');

    $this->artisan('swissstreets:uninstall', ['--force' => true])
        ->expectsOutputToContain('Removed the nightly import from routes/console.php.')
        ->assertSuccessful();

    expect(File::get(base_path('routes/console.php')))->toBe("<?php\n\nSchedule::command('inspire')->hourly();\n");
});

it('runs the import when asked to at the end of the install', function () {
    Http::fake(function ($request) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200, ['Last-Modified' => 'x'])
            : Http::response('', 200);
    });
    $downloader = Mockery::mock(Downloader::class)->makePartial();
    $downloader->shouldReceive('download')->once()->andReturn(fixturePath('register.csv'));
    app()->instance(Downloader::class, $downloader);

    $this->artisan('swissstreets:install')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
        ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'no')
        ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'yes')
        ->expectsOutputToContain('Initial import')
        ->assertSuccessful();

    expect(Address::count())->toBe(8);
});

it('exits non-zero and points at the import command when the import fails', function () {
    Sleep::fake();
    Http::fake(['*' => Http::failedConnection()]);

    $this->artisan('swissstreets:install')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
        ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'no')
        ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'yes')
        ->expectsOutputToContain('Could not reach swisstopo')
        ->expectsOutputToContain('Run it again: php artisan swissstreets:import')
        ->doesntExpectOutputToContain('cURL error')
        ->assertFailed();

    expect(Address::count())->toBe(0);
});

it('enables the health check in the published config when asked', function () {
    config()->set('swissstreets-for-filament.health.enabled', false);

    $this->artisan('swissstreets:install')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
        ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'yes')
        ->expectsOutputToContain('Enabled health.enabled in config/swissstreets-for-filament.php.')
        ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'no')
        ->assertSuccessful();

    $config = require config_path('swissstreets-for-filament.php');

    expect($config['health']['enabled'])->toBeTrue()
        ->and($config['health']['max_age_days'])->toBe(21);
});

it('does not ask about the health check when it is already enabled', function () {
    config()->set('swissstreets-for-filament.health.enabled', true);

    $this->artisan('swissstreets:install')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
        ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'no')
        ->assertSuccessful();
});

it('offers a nightly minute hashed from app and plugin name', function () {
    config()->set('app.name', 'fotimo');
    $time = ScheduleInstaller::defaultTime();

    expect($time)->toBe('04:09')
        ->and(ScheduleInstaller::isValidTime($time))->toBeTrue()
        ->and($time)->toBeGreaterThanOrEqual('03:00')->toBeLessThanOrEqual('04:59');

    config()->set('app.name', 'audiobeam');
    expect(ScheduleInstaller::defaultTime())->toBe('03:26');

    // The Laravel default name would make every unconfigured app collide — the URL steps in.
    config()->set('app.name', 'Laravel');
    config()->set('app.url', 'https://a.example');
    $a = ScheduleInstaller::defaultTime();
    config()->set('app.url', 'https://b.example');

    expect(ScheduleInstaller::defaultTime())->not->toBe($a);
});

function fakeRegisterDownload(): void
{
    Http::fake(fn ($request) => $request->method() === 'HEAD'
        ? Http::response('', 200, ['Last-Modified' => 'x'])
        : Http::response('', 200));
    $downloader = Mockery::mock(Downloader::class)->makePartial();
    $downloader->shouldReceive('download')->once()->andReturn(fixturePath('register.csv'));
    app()->instance(Downloader::class, $downloader);
}

it('migrates and imports headless under --no-interaction', function () {
    config()->set('swissstreets-for-filament.health.enabled', true);
    Schema::drop('swissstreets_addresses');
    fakeRegisterDownload();

    // The artisan() test harness mocks every prompt; a real --no-interaction run needs the real console.
    expect(Artisan::call('swissstreets:install', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())
        ->toContain('Running migrations...')
        ->toContain('Initial import');

    expect(Schema::hasTable('swissstreets_addresses'))->toBeTrue()
        ->and(Address::count())->toBe(8)
        ->and(File::get(base_path('routes/console.php')))->toContain("->dailyAt('" . ScheduleInstaller::defaultTime() . "')");
});

it('asks with default yes and runs migrate --force when the table is missing', function () {
    Schema::drop('swissstreets_addresses');

    $this->artisan('swissstreets:install')
        ->expectsConfirmation('Run the migrations now? (creates swissstreets_addresses)', 'yes')
        ->expectsOutputToContain('Running migrations...')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
        ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'no')
        ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'no')
        ->assertSuccessful();

    expect(Schema::hasTable('swissstreets_addresses'))->toBeTrue();
});

it('skips the migration prompt when the table exists and never stacks migration files', function () {
    foreach (range(1, 2) as $i) {
        $this->artisan('swissstreets:install')
            ->expectsOutputToContain('Table swissstreets_addresses exists, migration already run.')
            ->doesntExpectOutputToContain('Running migrations...')
            ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
            ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'no')
            ->expectsConfirmation('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', 'no')
            ->assertSuccessful();
    }

    expect(File::glob(database_path('migrations/*_create_swissstreets_addresses_table.php')))->toHaveCount(1);
});

it('refuses the import and exits non-zero when the migration was declined', function () {
    Schema::drop('swissstreets_addresses');

    $this->artisan('swissstreets:install')
        ->expectsConfirmation('Run the migrations now? (creates swissstreets_addresses)', 'no')
        ->expectsOutputToContain('Skipped. Before the first import: php artisan migrate')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
        ->expectsConfirmation('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', 'no')
        ->expectsOutputToContain('Table swissstreets_addresses is missing. Run: php artisan migrate')
        ->assertFailed();

    expect(Schema::hasTable('swissstreets_addresses'))->toBeFalse();
});

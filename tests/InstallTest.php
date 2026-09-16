<?php

use Blemli\Swissstreets\Support\ScheduleInstaller;
use Illuminate\Support\Facades\File;

it('asks for a time and writes the nightly import into routes/console.php', function () {
    $this->artisan('swissstreets-for-filament:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '04:15')
        ->expectsOutputToContain('Created routes/console.php with the nightly import at 04:15.')
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
    $this->artisan('swissstreets-for-filament:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '')
        ->expectsOutputToContain('Skipped. Add it yourself when you are ready:')
        ->expectsOutputToContain("Schedule::command('swissstreets:import')")
        ->assertSuccessful();

    expect(File::exists(base_path('routes/console.php')))->toBeFalse();
});

it('rejects a malformed time until a valid one is given', function () {
    $this->artisan('swissstreets-for-filament:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->expectsQuestion('Schedule the nightly address import at (HH:MM, empty to skip)', '3 am')
        ->expectsQuestion('Please enter a time as HH:MM (empty to skip)', '23:59')
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

<?php

namespace Blemli\Swissstreets;

use Blemli\Swissstreets\Commands\ImportCommand;
use Blemli\Swissstreets\Commands\InstallCommand;
use Blemli\Swissstreets\Commands\UninstallCommand;
use Blemli\Swissstreets\Health\HealthIntegration;
use Blemli\Swissstreets\Support\ScheduleInstaller;
use Illuminate\Support\Facades\File;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SwissstreetsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'swissstreets-for-filament';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('create_swissstreets_addresses_table')
            ->hasCommands([ImportCommand::class, UninstallCommand::class]);

        $install = (new InstallCommand($package))
            ->publishConfigFile()
            ->publishMigrations()
            ->askToRunMigrations()
            ->endWith(function (InstallCommand $command): void {
                $this->askForSchedule($command);
                $this->askForHealthCheck($command);
                $this->askToImport($command);
            });

        $package->consoleCommands[] = $install;
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Swissstreets::class);
    }

    public function packageBooted(): void
    {
        // After every provider: the panel plugin may have switched health.enabled on.
        $this->app->booted(fn () => HealthIntegration::register());
    }

    /**
     * spatie/laravel-health present: offer the register check. The switch lives in
     * the published config (or SwissstreetsPlugin::make()->health()) — never in
     * the app's providers, that is the developer's code.
     */
    protected function askForHealthCheck(InstallCommand $command): void
    {
        if (! HealthIntegration::installed() || HealthIntegration::enabled()) {
            return;
        }

        if (! $command->confirm('spatie/laravel-health is installed. Enable the address register health check (red after 21 days without changes, yellow after 3 failed imports)?', true)) {
            return;
        }

        $config = config_path('swissstreets-for-filament.php');
        $pattern = "/('health' => \[\s*(?:\/\/[^\n]*\n\s*)*'enabled' => )false/";

        if (File::exists($config) && preg_match($pattern, File::get($config))) {
            File::put($config, (string) preg_replace($pattern, '$1true', File::get($config), 1));
            $command->info('Enabled health.enabled in config/swissstreets-for-filament.php.');

            return;
        }

        $command->line('Add ->health() to your panel: SwissstreetsPlugin::make()->health()');
    }

    /**
     * Two million addresses take a while — worth a question, not a surprise.
     */
    protected function askToImport(InstallCommand $command): void
    {
        if (! $command->confirm('Import the Swiss address register now? (downloads ~140 MB, takes a few minutes)', true)) {
            $command->line('Later then: php artisan swissstreets:import');

            return;
        }

        if ($command->call('swissstreets:import') !== InstallCommand::SUCCESS) {
            // The importer already said what went wrong; the install still exits non-zero
            // so provisioning scripts notice, and the next step is spelled out.
            $command->importFailed = true;
            $command->warn('The address import did not finish. Run it again: php artisan swissstreets:import');
        }
    }

    /**
     * The nightly import is never registered behind the developer's back:
     * the installer asks for a time and writes the entry into routes/console.php.
     */
    protected function askForSchedule(InstallCommand $command): void
    {
        $installer = new ScheduleInstaller;

        if ($installer->isInstalled()) {
            $command->line('Nightly import already scheduled in routes/console.php.');

            return;
        }

        $default = ScheduleInstaller::defaultTime();
        $time = trim((string) $command->ask('Schedule the nightly address import at (HH:MM, empty to skip)', $default));

        while ($time !== '' && ! ScheduleInstaller::isValidTime($time)) {
            $time = trim((string) $command->ask('Please enter a time as HH:MM (empty to skip)', $default));
        }

        if ($time === '') {
            $command->line('Skipped. Add it yourself when you are ready:');
            $command->line($installer->snippet($default));

            return;
        }

        $result = $installer->install($time);
        $command->info($result === 'created'
            ? "Created routes/console.php with the nightly import at {$time}."
            : "Added the nightly import at {$time} to routes/console.php.");
    }
}

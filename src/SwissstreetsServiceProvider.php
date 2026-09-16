<?php

namespace Blemli\Swissstreets;

use Blemli\Swissstreets\Commands\ImportCommand;
use Blemli\Swissstreets\Commands\UninstallCommand;
use Blemli\Swissstreets\Support\ScheduleInstaller;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
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
            ->hasCommands([ImportCommand::class, UninstallCommand::class])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->endWith(function (InstallCommand $command): void {
                        $this->askForSchedule($command);
                        $command->line('Fill the register with: php artisan swissstreets:import');
                    });
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Swissstreets::class);
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

        $time = trim((string) $command->ask('Schedule the nightly address import at (HH:MM, empty to skip)', '03:00'));

        while ($time !== '' && ! ScheduleInstaller::isValidTime($time)) {
            $time = trim((string) $command->ask('Please enter a time as HH:MM (empty to skip)', '03:00'));
        }

        if ($time === '') {
            $command->line('Skipped. Add it yourself when you are ready:');
            $command->line($installer->snippet('03:00'));

            return;
        }

        $result = $installer->install($time);
        $command->info($result === 'created'
            ? "Created routes/console.php with the nightly import at {$time}."
            : "Added the nightly import at {$time} to routes/console.php.");
    }
}

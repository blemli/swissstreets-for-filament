<?php

namespace Blemli\Swissstreets;

use Blemli\Swissstreets\Commands\ImportCommand;
use Blemli\Swissstreets\Commands\UninstallCommand;
use Illuminate\Console\Scheduling\Schedule;
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
                        $command->line('Fill the register with: php artisan swissstreets:import');
                    });
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Swissstreets::class);
    }

    public function packageBooted(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $time = config('swissstreets-for-filament.schedule');

            if (blank($time)) {
                return;
            }

            $schedule->command('swissstreets:import')
                ->dailyAt((string) $time)
                ->withoutOverlapping(120)
                ->runInBackground();
        });
    }
}

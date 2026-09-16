<?php

namespace Blemli\Swissstreets\Commands;

use Spatie\LaravelPackageTools\Commands\InstallCommand as PackageInstallCommand;
use Spatie\LaravelPackageTools\Package;

/**
 * package-tools would call this "swissstreets-for-filament:install";
 * "swissstreets:install" matches the other commands.
 */
class InstallCommand extends PackageInstallCommand
{
    public function __construct(Package $package)
    {
        parent::__construct($package);

        $this->setName('swissstreets:install');
        $this->setDescription('Install swissstreets-for-filament: publish config and migration, schedule and run the import');
        $this->setHidden(false);
    }

    /** Set by the optional import at the end; config, migration and schedule are done regardless. */
    public bool $importFailed = false;

    public function handle(): int
    {
        parent::handle();

        return $this->importFailed ? self::FAILURE : self::SUCCESS;
    }
}

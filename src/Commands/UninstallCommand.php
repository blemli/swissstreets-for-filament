<?php

namespace Blemli\Swissstreets\Commands;

use Blemli\Swissstreets\Import\Downloader;
use Blemli\Swissstreets\Import\ImportLock;
use Blemli\Swissstreets\Import\ImportStatus;
use Blemli\Swissstreets\Support\ScheduleInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\confirm;

class UninstallCommand extends Command
{
    public $signature = 'swissstreets:uninstall {--force : Skip all confirmation prompts}';

    public $description = 'Uninstall swissstreets-for-filament: drop the address table and remove published files';

    public function handle(): int
    {
        $this->dropTable();
        $this->removePublishedFiles();
        $this->removeStorage();
        $this->removeSchedule();
        $this->forgetImportState();

        $registrations = $this->findPluginRegistrations();

        if ($this->option('force')) {
            $this->warnAboutRegistrations($registrations);
        } else {
            while ($registrations !== []) {
                $this->warnAboutRegistrations($registrations);

                if (! confirm('Removed it?', default: false)) {
                    break;
                }

                $registrations = $this->findPluginRegistrations();
            }

            if ($registrations === []) {
                $this->info('No SwissstreetsPlugin registration left.');
            }
        }

        if (! $this->option('force') && confirm('Run "composer remove blemli/swissstreets-for-filament" now?', default: $registrations === [])) {
            Process::path(base_path())
                ->forever()
                ->run(['composer', 'remove', 'blemli/swissstreets-for-filament'], fn (string $type, string $output) => $this->output->write($output));

            $this->info('swissstreets-for-filament was uninstalled.');
        } else {
            $this->info('swissstreets-for-filament was uninstalled. Finish with: composer remove blemli/swissstreets-for-filament');
        }

        return self::SUCCESS;
    }

    protected function dropTable(): void
    {
        $table = (string) config('swissstreets-for-filament.table_name', 'swissstreets_addresses');

        if (! Schema::hasTable($table)) {
            return;
        }

        $this->info("Table to drop: {$table}");

        if ($this->option('force') || confirm("Drop table {$table}?")) {
            Schema::drop($table);
            $this->line("  Dropped {$table}.");
        }
    }

    protected function removePublishedFiles(): void
    {
        $paths = [
            config_path('swissstreets-for-filament.php'),
            lang_path('vendor/swissstreets-for-filament'),
            resource_path('views/vendor/swissstreets-for-filament'),
            public_path('css/blemli/swissstreets-for-filament'),
            public_path('js/blemli/swissstreets-for-filament'),
            ...File::glob(database_path('migrations/*_create_swissstreets_addresses_table.php')),
        ];

        $paths = array_values(array_filter($paths, fn (string $path): bool => File::exists($path)));

        if ($paths === []) {
            $this->info('Nothing published to remove.');

            return;
        }

        $this->info('The following will be removed:');

        foreach ($paths as $path) {
            $this->line("  - {$path}");
        }

        if (! $this->option('force') && ! confirm('Delete published files?')) {
            return;
        }

        foreach ($paths as $path) {
            File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
        }

        foreach ([public_path('css/blemli'), public_path('js/blemli')] as $dir) {
            if (File::isDirectory($dir) && File::allFiles($dir) === [] && File::directories($dir) === []) {
                File::deleteDirectory($dir);
            }
        }
    }

    protected function removeSchedule(): void
    {
        $installer = new ScheduleInstaller;

        if (! $installer->isInstalled()) {
            return;
        }

        $this->info('Nightly import entry found in routes/console.php.');

        if ($this->option('force') || confirm('Remove it?')) {
            $installer->remove();
            $this->line('  Removed the nightly import from routes/console.php.');
        }
    }

    /**
     * A reinstall within the lock TTL of a killed run must not hit "still running",
     * and a fresh table must not skip the download as "unchanged".
     */
    protected function forgetImportState(): void
    {
        (new ImportLock)->forceRelease();
        (new ImportStatus)->forget();
        Cache::forget(Downloader::VERSION_CACHE_KEY);
    }

    protected function removeStorage(): void
    {
        $dir = storage_path(trim((string) config('swissstreets-for-filament.storage_path', 'app/swissstreets'), '/'));

        if (! File::isDirectory($dir)) {
            return;
        }

        $this->info("Downloaded register to remove: {$dir}");

        if ($this->option('force') || confirm('Delete it?')) {
            File::deleteDirectory($dir);
        }
    }

    /**
     * @param  array<string>  $registrations
     */
    protected function warnAboutRegistrations(array $registrations): void
    {
        if ($registrations === []) {
            return;
        }

        $this->warn('SwissstreetsPlugin is still registered in your panel provider(s) — remove the ->plugin(SwissstreetsPlugin::make()...) call or the app will crash after composer remove:');

        foreach ($registrations as $location) {
            $this->line("  - {$location}");
        }
    }

    /**
     * The provider file is never edited automatically — that is the user's code.
     *
     * @return array<string>
     */
    protected function findPluginRegistrations(): array
    {
        $locations = [];

        if (! File::isDirectory(app_path('Providers'))) {
            return $locations;
        }

        foreach (File::allFiles(app_path('Providers')) as $file) {
            foreach (explode("\n", File::get($file->getPathname())) as $index => $line) {
                if (str_contains($line, 'SwissstreetsPlugin')) {
                    $locations[] = $file->getPathname() . ':' . ($index + 1);
                }
            }
        }

        return $locations;
    }
}

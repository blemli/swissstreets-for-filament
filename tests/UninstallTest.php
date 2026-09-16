<?php

use Blemli\Swissstreets\Commands\UninstallCommand;
use Blemli\Swissstreets\Import\Downloader;
use Blemli\Swissstreets\Import\ImportLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

function fakePublishedPaths(): array
{
    return [
        config_path('swissstreets-for-filament.php'),
        lang_path('vendor/swissstreets-for-filament/de/swissstreets.php'),
        resource_path('views/vendor/swissstreets-for-filament/x.blade.php'),
        public_path('css/blemli/swissstreets-for-filament/x.css'),
        public_path('js/blemli/swissstreets-for-filament/x.js'),
        database_path('migrations/2026_01_01_000000_create_swissstreets_addresses_table.php'),
        storage_path('app/swissstreets/register.csv.zip'),
    ];
}

function publishFakeArtifacts(): array
{
    foreach (fakePublishedPaths() as $path) {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, str_ends_with($path, '.php')
            ? (str_contains($path, 'migrations') ? "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void {} };\n" : '<?php return [];')
            : 'x');
    }

    return fakePublishedPaths();
}

it('drops the table and removes every published artifact', function () {
    $paths = publishFakeArtifacts();
    expect(Schema::hasTable('swissstreets_addresses'))->toBeTrue();

    $this->artisan('swissstreets:uninstall', ['--force' => true])
        ->expectsOutputToContain('Dropped swissstreets_addresses')
        ->assertSuccessful();

    foreach ($paths as $path) {
        expect(File::exists($path))->toBeFalse($path . ' should have been removed');
    }

    expect(Schema::hasTable('swissstreets_addresses'))->toBeFalse()
        ->and(File::isDirectory(public_path('css/blemli')))->toBeFalse()
        ->and(File::isDirectory(public_path('js/blemli')))->toBeFalse();
});

it('points to panel providers that still register the plugin', function () {
    $provider = app_path('Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($provider));
    File::put($provider, "<?php\n\n// ...\n\$panel->plugin(SwissstreetsPlugin::make());\n");

    $this->artisan('swissstreets:uninstall', ['--force' => true])
        ->expectsOutputToContain('SwissstreetsPlugin is still registered')
        ->expectsOutputToContain('AdminPanelProvider.php:4')
        ->assertSuccessful();
});

it('loops until the registration is gone and never edits the provider', function () {
    $provider = app_path('Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($provider));
    $source = "<?php\n\n\$panel->plugin(SwissstreetsPlugin::make());\n";
    File::put($provider, $source);

    $this->artisan('swissstreets:uninstall')
        ->expectsConfirmation('Drop table swissstreets_addresses?', 'yes')
        ->expectsConfirmation('Removed it?', 'yes')
        ->expectsOutputToContain('SwissstreetsPlugin is still registered')
        ->expectsConfirmation('Removed it?', 'no')
        ->expectsConfirmation('Run "composer remove blemli/swissstreets-for-filament" now?', 'no')
        ->expectsOutputToContain('Finish with: composer remove')
        ->assertSuccessful();

    expect(File::get($provider))->toBe($source);
});

it('exits the loop once the registration disappears', function () {
    $command = new class extends UninstallCommand
    {
        public int $scans = 0;

        protected function findPluginRegistrations(): array
        {
            return ++$this->scans === 1 ? ['app/Providers/Filament/AdminPanelProvider.php:12'] : [];
        }
    };

    app(Kernel::class)->registerCommand($command);

    $this->artisan('swissstreets:uninstall')
        ->expectsConfirmation('Drop table swissstreets_addresses?', 'no')
        ->expectsConfirmation('Removed it?', 'yes')
        ->expectsOutputToContain('No SwissstreetsPlugin registration left')
        ->expectsConfirmation('Run "composer remove blemli/swissstreets-for-filament" now?', 'no')
        ->assertSuccessful();

    expect($command->scans)->toBe(2);
});

it('never asks under --force', function () {
    publishFakeArtifacts();

    $this->artisan('swissstreets:uninstall', ['--force' => true])
        ->doesntExpectOutputToContain('Removed it?')
        ->assertSuccessful();
});

it('keeps console output English regardless of locale', function () {
    app()->setLocale('de');

    $this->artisan('swissstreets:uninstall', ['--force' => true])
        ->expectsOutputToContain('was uninstalled')
        ->assertSuccessful();
});

it('forgets the import lock and the version stamp', function () {
    Cache::lock(ImportLock::KEY, 60)->get();
    Cache::put(ImportLock::META_KEY, ['pid' => 1, 'host' => 'x', 'started_at' => now()->toIso8601String(), 'trigger' => 'cli'], 60);
    Cache::forever(Downloader::VERSION_CACHE_KEY, 'v1');

    $this->artisan('swissstreets:uninstall', ['--force' => true])->assertSuccessful();

    expect((new ImportLock)->isLocked())->toBeFalse()
        ->and((new ImportLock)->metadata())->toBeNull()
        ->and(Cache::get(Downloader::VERSION_CACHE_KEY))->toBeNull();
});

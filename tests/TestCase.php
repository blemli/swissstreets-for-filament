<?php

namespace Blemli\Swissstreets\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Blemli\Swissstreets\SwissstreetsServiceProvider;
use Blemli\Swissstreets\Tests\Fixtures\AdminPanelProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\Health\HealthServiceProvider;

class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations();

        // The package ships its migration as a .stub (published with a
        // timestamp on install), so the migrator never sees it — run it by hand.
        $migration = include __DIR__ . '/../database/migrations/create_swissstreets_addresses_table.php.stub';
        Schema::dropIfExists('swissstreets_addresses');
        $migration->up();

        Schema::dropIfExists('activity_log');
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('notifications');
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('customers');
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('address_id')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('shoots');
        Schema::create('shoots', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('vendors');
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        // Anything the suite writes into the shared testbench skeleton must
        // go — leftovers poison every later test.
        foreach ([
            config_path('swissstreets-for-filament.php'),
            lang_path('vendor/swissstreets-for-filament'),
            resource_path('views/vendor/swissstreets-for-filament'),
            public_path('css/blemli'),
            public_path('js/blemli'),
            app_path('Providers/Filament'),
            storage_path('app/swissstreets'),
            base_path('routes/console.php'),
        ] as $path) {
            File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
        }

        foreach (File::glob(database_path('migrations/*_create_swissstreets_addresses_table.php')) as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        $providers = [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            SwissstreetsServiceProvider::class,
            ActivitylogServiceProvider::class,
            HealthServiceProvider::class,
        ];

        sort($providers);

        return [...$providers, AdminPanelProvider::class];
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', Fixtures\User::class);
        $app['config']->set('swissstreets-for-filament.log_initial_import', true);
    }
}

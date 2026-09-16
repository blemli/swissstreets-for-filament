<?php

use Blemli\Swissstreets\Import\Downloader;
use Blemli\Swissstreets\Import\Importer;
use Blemli\Swissstreets\Import\ImportFailed;
use Blemli\Swissstreets\Import\ImportLock;
use Blemli\Swissstreets\Jobs\ImportRegister;
use Blemli\Swissstreets\Models\Address;
use Blemli\Swissstreets\Resources\AddressResource;
use Blemli\Swissstreets\Resources\AddressResource\Pages\ListAddresses;
use Blemli\Swissstreets\Tests\Fixtures\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    importFixture();
    $this->user = loginUser();
    bootPanel();
});

it('queues the import and tells the clicker', function () {
    Queue::fake();

    Livewire::test(ListAddresses::class)
        ->assertActionVisible('import')
        ->assertActionEnabled('import')
        ->callAction('import')
        ->assertNotified('Import queued — you will be notified when it is done.');

    Queue::assertPushed(ImportRegister::class, fn (ImportRegister $job): bool => $job->user?->is($this->user) && ! $job->force);
});

it('is disabled and says "running" while an import holds the lock', function () {
    Queue::fake();
    holdImportLock(['pid' => 4711, 'host' => 'elsewhere']);

    Livewire::test(ListAddresses::class)
        ->assertActionDisabled('import')
        ->assertActionHasLabel('import', 'Import running…');

    Queue::assertNothingPushed();
});

it('hides the button when the policy denies import', function () {
    Gate::policy(Address::class, new class
    {
        public function viewAny(): bool
        {
            return true;
        }

        public function import(): bool
        {
            return false;
        }
    }::class);

    expect(AddressResource::canImport())->toBeFalse();

    Livewire::test(ListAddresses::class)->assertActionHidden('import');
});

it('declares the import permission for Filament Shield', function () {
    expect(AddressResource::getPermissionPrefixes())->toContain('import')
        ->and(class_implements(AddressResource::class))->toContain('BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions');
});

it('runs the same import as the CLI and notifies the clicker with the result', function () {
    $user = User::create(['name' => 'Clicker', 'email' => 'c@example.com', 'password' => 'x']);
    Address::find(200000002)->delete();

    (new ImportRegister($user, file: fixturePath('register.csv')))->handle(app(Importer::class));

    expect(Address::find(200000002)->trashed())->toBeFalse()
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toBe('Address import finished')
        ->and($user->notifications()->first()->data['body'])->toContain('1 restored');
});

it('tells the clicker when the register is unchanged, which the recipients never hear', function () {
    $user = User::create(['name' => 'Clicker', 'email' => 'c@example.com', 'password' => 'x']);
    Cache::forever(Downloader::VERSION_CACHE_KEY, 'same');
    Http::fake(['*' => Http::response('', 200, ['Last-Modified' => 'same'])]);

    (new ImportRegister($user))->handle(app(Importer::class));

    expect($user->notifications()->first()->data['body'])->toBe('The register has not changed since the last import.');
});

it('does not notify a clicker twice when they are a configured recipient', function () {
    // The fixture panel notifies every User — the clicker is one of them.
    $user = User::create(['name' => 'Clicker', 'email' => 'c@example.com', 'password' => 'x']);

    (new ImportRegister($user, file: fixturePath('register.csv')))->handle(app(Importer::class));

    expect($user->notifications()->count())->toBe(1);
});

it('notifies the clicker when the import fails', function () {
    Sleep::fake();
    $user = User::create(['name' => 'Clicker', 'email' => 'c@example.com', 'password' => 'x']);
    config()->set('swissstreets-for-filament.notify', null);
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => (new ImportRegister($user))->handle(app(Importer::class)))
        ->toThrow(ImportFailed::class);

    expect($user->notifications()->first()->data['title'])->toBe('Address import failed')
        ->and($user->notifications()->first()->data['body'])->toContain('Could not reach swisstopo');
});

it('records the panel as the trigger in the lock', function () {
    $lock = Mockery::mock(ImportLock::class)->makePartial();
    $lock->shouldReceive('acquire')->once()->with('panel')->andReturn(true);
    app()->instance(ImportLock::class, $lock);

    (new ImportRegister(null, file: fixturePath('register.csv')))->handle(app(Importer::class));
});

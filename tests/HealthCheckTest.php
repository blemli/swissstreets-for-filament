<?php

use Blemli\Swissstreets\Health\AddressRegisterCheck;
use Blemli\Swissstreets\Health\HealthIntegration;
use Blemli\Swissstreets\Import\Importer;
use Blemli\Swissstreets\Import\ImportStatus;
use Blemli\Swissstreets\Models\Address;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;

it('is registered with spatie/laravel-health by the plugin option', function () {
    bootPanel();

    expect(config('swissstreets-for-filament.health.enabled'))->toBeTrue()
        ->and(HealthIntegration::enabled())->toBeTrue();

    HealthIntegration::register();
    HealthIntegration::register();

    $checks = collect(Health::registeredChecks())->filter(fn ($check) => $check instanceof AddressRegisterCheck);

    expect($checks)->toHaveCount(1)
        ->and($checks->first()->getLabel())->toBe('Swiss address register')
        ->and($checks->first()->getName())->toBe('AddressRegister');
});

it('stays out of the registry when disabled', function () {
    config()->set('swissstreets-for-filament.health.enabled', false);
    Health::clearChecks();

    HealthIntegration::register();

    expect(Health::registeredChecks())->toBeEmpty();
});

it('is red while nothing was imported', function () {
    $result = AddressRegisterCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->getNotificationMessage())->toBe('No addresses imported yet.');
});

it('is green right after an import and red after 21 quiet days', function () {
    importFixture();

    $fresh = AddressRegisterCheck::new()->run();
    expect($fresh->status)->toBe(Status::ok())
        ->and($fresh->getShortSummary())->toBe('0 d');

    $this->travel(20)->days();
    expect(AddressRegisterCheck::new()->run()->status)->toBe(Status::ok());

    $this->travel(2)->days();
    $stale = AddressRegisterCheck::new()->run();

    expect($stale->status)->toBe(Status::failed())
        ->and($stale->getNotificationMessage())->toBe('No address changed for 22 days (limit 21).')
        ->and($stale->getShortSummary())->toBe('22 d');
});

it('counts a removal or a manual address as a change', function () {
    importFixture();
    $this->travel(30)->days();
    expect(AddressRegisterCheck::new()->run()->status)->toBe(Status::failed());

    Address::find(200000002)->delete();
    expect(AddressRegisterCheck::new()->run()->status)->toBe(Status::ok());

    $this->travel(30)->days();
    Address::createManual(['street' => 'Musterweg', 'zip' => '12345', 'locality' => 'Berlin', 'country' => 'DE']);
    expect(AddressRegisterCheck::new()->run()->status)->toBe(Status::ok());
});

it('respects a custom threshold', function () {
    importFixture();
    $this->travel(5)->days();

    expect(AddressRegisterCheck::new()->maxAgeInDays(3)->run()->status)->toBe(Status::failed())
        ->and(AddressRegisterCheck::new()->maxAgeInDays(7)->run()->status)->toBe(Status::ok());

    config()->set('swissstreets-for-filament.health.max_age_days', 4);
    expect(AddressRegisterCheck::new()->run()->status)->toBe(Status::failed());
});

it('turns yellow after three failed imports in a row and recovers on success', function () {
    importFixture();
    Sleep::fake();
    Http::fake(['*' => Http::failedConnection()]);

    foreach (range(1, 2) as $i) {
        try {
            app(Importer::class)->run();
        } catch (Throwable) {
        }
    }

    expect((new ImportStatus)->consecutiveFailures())->toBe(2)
        ->and(AddressRegisterCheck::new()->run()->status)->toBe(Status::ok());

    try {
        app(Importer::class)->run();
    } catch (Throwable) {
    }

    $result = AddressRegisterCheck::new()->run();

    expect($result->status)->toBe(Status::warning())
        ->and($result->getNotificationMessage())->toStartWith('The last 3 imports failed: Could not reach swisstopo')
        ->and($result->getShortSummary())->toBe('3 failed');

    importFixture();

    expect((new ImportStatus)->consecutiveFailures())->toBe(0)
        ->and((new ImportStatus)->lastSuccessAt())->not->toBeNull()
        ->and(AddressRegisterCheck::new()->run()->status)->toBe(Status::ok());
});

it('does not count a lock refusal as a failed run', function () {
    importFixture();
    holdImportLock(['pid' => 1, 'host' => 'elsewhere']);

    try {
        app(Importer::class)->run(fixturePath('register.csv'));
    } catch (Throwable) {
    }

    expect((new ImportStatus)->consecutiveFailures())->toBe(0);
});

it('works with a custom table name', function () {
    config()->set('swissstreets-for-filament.table_name', 'my_addresses');
    Schema::rename('swissstreets_addresses', 'my_addresses');

    importFixture();

    expect(AddressRegisterCheck::new()->run()->status)->toBe(Status::ok());
    Schema::rename('my_addresses', 'swissstreets_addresses');
});

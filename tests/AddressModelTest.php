<?php

use Blemli\Swissstreets\Facades\Swissstreets;
use Blemli\Swissstreets\Models\Address;
use Blemli\Swissstreets\Tests\Fixtures\Customer;
use Blemli\Swissstreets\Tests\Fixtures\Vendor;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => importFixture());

it('searches across street, number, zip and town', function () {
    expect(Address::search('spalen')->pluck('egaid')->all())->toEqualCanonicalizing([100297441, 100297442, 100297443])
        ->and(Address::search('SPALEN')->count())->toBe(3)
        ->and(Address::search('Im langen')->count())->toBe(1)
        ->and(Address::search('im loh 19')->count())->toBe(1)
        ->and(Address::search('langen')->count())->toBe(0)
        ->and(Address::search('langen', contains: true)->count())->toBe(1)
        ->and(Address::search('zürich')->count())->toBe(2)
        ->and(Address::search('zurich')->count())->toBe(2)
        ->and(Address::search('ZÜRICH bahnhof')->count())->toBe(2)
        ->and(Address::search('ecublens')->count())->toBe(1)
        ->and(Address::search('église')->count())->toBe(0)
        ->and(Address::search('église', contains: true)->count())->toBe(1)
        ->and(Address::search('rue eglise 5')->count())->toBe(1)
        ->and(Address::search('Écublens')->count())->toBe(1)
        ->and(Address::search('spalen 11')->pluck('egaid')->all())->toEqualCanonicalizing([100297441, 100297442, 100297443])
        ->and(Address::search('spalen 113')->pluck('egaid')->all())->toBe([100297441])
        ->and(Address::search('4055 spalen')->count())->toBe(3)
        ->and(Address::search('Basel')->count())->toBe(4)
        ->and(Address::search('bahnhof zürich')->count())->toBe(2)
        ->and(Address::search('nowhere')->count())->toBe(0);
});

it('filters residential buildings', function () {
    expect(Address::residential()->count())->toBe(7)
        ->and(Address::find(100297443)->isResidential())->toBeFalse();
});

it('orders by vicinity without a spatial extension', function () {
    // From Zürich HB: Bahnhofstrasse first, Écublens (VD) last.
    $ordered = Address::near(47.3779, 8.5403)->pluck('egaid')->all();

    expect(array_slice($ordered, 0, 2))->toEqualCanonicalizing([200000001, 200000002])
        ->and(end($ordered))->toBe(400000001);

    expect(Address::near(47.3779, 8.5403, withinKm: 5)->count())->toBe(2)
        ->and(Address::find(200000001)->distanceTo(47.3779, 8.5403))->toBeLessThan(1.0);
});

it('knows which addresses are used through the HasAddress trait', function () {
    Customer::create(['name' => 'Alice', 'address_id' => 100297441]);
    Vendor::create(['name' => 'Bob', 'site_id' => 200000001]);

    expect(Swissstreets::usages())->toHaveKeys([Customer::class . '.address_id', Vendor::class . '.site_id'])
        ->and(Address::used()->pluck('egaid')->all())->toEqualCanonicalizing([100297441, 200000001])
        ->and(Customer::first()->address->line)->toBe('Spalenring 113, 4055 Basel')
        ->and(Vendor::first()->address->line)->toBe('Bahnhofstrasse 1, 8001 Zürich');
});

it('keeps the relation after the address was removed from the register', function () {
    $customer = Customer::create(['name' => 'Alice', 'address_id' => 100297441]);
    Address::find(100297441)->delete();

    expect($customer->fresh()->address?->line)->toBe('Spalenring 113, 4055 Basel');
});

it('links to map.geo.admin.ch', function () {
    expect(Address::find(100297441)->mapUrl())->toStartWith('https://map.geo.admin.ch/?E=2610314&N=1267321');
});

it('discovers trait users through the panel before their model booted', function () {
    // Insert without touching the Customer model, so bootHasAddress never ran.
    DB::table('customers')->insert(['name' => 'Alice', 'address_id' => 100297441, 'created_at' => now(), 'updated_at' => now()]);
    bootPanel();

    expect(Address::used()->pluck('egaid')->all())->toBe([100297441]);
});

it('sorts rows without coordinates last and keeps them out of a radius', function () {
    $berlin = Address::createManual(['street' => 'Musterweg', 'zip' => '12345', 'locality' => 'Berlin', 'country' => 'DE']);

    $ordered = Address::near(47.3779, 8.5403)->pluck('egaid')->all();

    expect(end($ordered))->toBe($berlin->egaid)
        ->and(Address::near(47.3779, 8.5403, withinKm: 5)->pluck('egaid')->all())->not->toContain($berlin->egaid)
        ->and($berlin->distanceTo(47.3779, 8.5403))->toBeNull()
        ->and($berlin->mapUrl())->toBeNull()
        ->and($berlin->isResidential())->toBeTrue()
        ->and(Address::residential()->pluck('egaid')->all())->toContain($berlin->egaid);
});

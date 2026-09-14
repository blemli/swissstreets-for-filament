<?php

use Blemli\Swissstreets\Models\Address;
use Blemli\Swissstreets\Resources\AddressResource;
use Blemli\Swissstreets\Resources\AddressResource\Pages\ListAddresses;
use Blemli\Swissstreets\Tests\Fixtures\Customer;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;

beforeEach(function () {
    importFixture();
    loginUser();
    bootPanel();
});

it('registers the addresses resource in the panel', function () {
    expect(Filament::getCurrentPanel()->getResources())->toContain(AddressResource::class)
        ->and(AddressResource::getNavigationIcon())->toBe(Heroicon::OutlinedMapPin)
        ->and(AddressResource::canCreate())->toBeFalse();
});

it('shows only used addresses by default', function () {
    Customer::create(['name' => 'Alice', 'address_id' => 100297441]);

    Livewire::test(ListAddresses::class)
        ->assertCanSeeTableRecords([Address::find(100297441)])
        ->assertCanNotSeeTableRecords([Address::find(200000001)])
        ->removeTableFilter('used')
        ->assertCanSeeTableRecords(Address::all());
});

it('searches, filters and shows removed addresses', function () {
    Address::find(200000002)->delete();

    Livewire::test(ListAddresses::class)
        ->removeTableFilter('used')
        ->searchTable('bahnhof 1 zürich')
        ->assertCanSeeTableRecords([Address::find(200000001)])
        ->assertCanNotSeeTableRecords([Address::find(300000001)])
        ->assertCanNotSeeTableRecords([Address::find(100297441)])
        ->searchTable('')
        ->filterTable('canton', ['BS'])
        ->assertCanSeeTableRecords(Address::where('canton', 'BS')->get())
        ->assertCanNotSeeTableRecords([Address::find(200000001)])
        ->resetTableFilters()
        ->removeTableFilter('used')
        ->filterTable('residential', true)
        ->assertCanNotSeeTableRecords([Address::find(100297443)])
        ->resetTableFilters()
        ->removeTableFilter('used')
        ->filterTable('trashed', 'with')
        ->assertCanSeeTableRecords([Address::withTrashed()->find(200000002)]);
});

it('renders in German', function () {
    app()->setLocale('de');

    Livewire::test(ListAddresses::class)
        ->assertSee('Nur verwendete')
        ->assertSee('Strasse');
});

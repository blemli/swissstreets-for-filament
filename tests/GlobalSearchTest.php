<?php

use Blemli\Swissstreets\Models\Address;
use Blemli\Swissstreets\Tests\Fixtures\Customer;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource;
use Filament\Livewire\GlobalSearch;
use Livewire\Livewire;

beforeEach(function () {
    importFixture();
    loginUser();
    bootPanel();

    Customer::create(['name' => 'Alice', 'address_id' => 100297441]); // Spalenring 113, 4055 Basel
    Customer::create(['name' => 'Bob', 'address_id' => 200000001]);   // Bahnhofstrasse 1, 8001 Zürich
    Customer::create(['name' => 'Carol']);
});

function globalSearchTitles(string $search): array
{
    return CustomerResource::getGlobalSearchResults($search)->map(fn ($result) => $result->title)->values()->all();
}

it('finds a record through its address', function () {
    expect(globalSearchTitles('spalen 113'))->toBe(['Alice'])
        ->and(globalSearchTitles('4055'))->toBe(['Alice'])
        ->and(globalSearchTitles('bahnhof'))->toBe(['Bob']);
});

it('matches accents the way the address field does', function () {
    expect(globalSearchTitles('zürich'))->toBe(['Bob'])
        ->and(globalSearchTitles('zurich'))->toBe(['Bob']);
});

it('still searches the other attributes and combines them per word', function () {
    expect(globalSearchTitles('alice'))->toBe(['Alice'])
        ->and(globalSearchTitles('alice basel'))->toBe(['Alice'])
        ->and(globalSearchTitles('bob basel'))->toBe([])
        ->and(globalSearchTitles('carol'))->toBe(['Carol']);
});

it('ignores removed addresses', function () {
    Address::find(200000001)->delete();

    expect(globalSearchTitles('bahnhof'))->toBe([])
        ->and(globalSearchTitles('bob'))->toBe(['Bob'])
        ->and(CustomerResource::getGlobalSearchResults('bob')->first()->details)->toBe([]);
});

it('shows the address as the result detail', function () {
    $result = CustomerResource::getGlobalSearchResults('alice')->first();

    expect($result->details)->toBe(['Address' => 'Spalenring 113, 4055 Basel'])
        ->and($result->url)->toContain('/customers/');
});

it('renders in the panel search box', function () {
    Livewire::test(GlobalSearch::class)
        ->set('search', 'spalenring')
        ->assertSee('Alice')
        ->assertSee('Spalenring 113, 4055 Basel')
        ->assertDontSee('Bob');
});

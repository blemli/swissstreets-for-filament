<?php

use Blemli\Swissstreets\Forms\Components\Address;
use Blemli\Swissstreets\Models\Address as AddressModel;
use Blemli\Swissstreets\Tests\Fixtures\Customer;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages\CreateCustomer;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages\EditCustomer;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages\ListCustomers;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Livewire;

beforeEach(function () {
    importFixture();
    loginUser();
    bootPanel();
});

function fieldSearch(Address $field, string $search): array
{
    return $field->getSearchQuery($search, contains: true)->limit(50)->get()->pluck('line', 'egaid')->all();
}

it('falls back to a contains match when no word starts with the input', function () {
    $field = Address::make('address_id');
    $field->container(Schema::make(new CreateCustomer)->statePath('data'));

    expect($field->getSearchResults('langen'))->toHaveKey('100265200')
        ->and($field->getSearchResults('Im langen 19'))->toHaveKey('100265200');
});

it('offers residential addresses only unless nonresidential() is set', function () {
    $field = Address::make('address_id');

    expect(array_keys(fieldSearch($field, 'spalen')))->toEqualCanonicalizing([100297441, 100297442]);

    expect(array_keys(fieldSearch($field->nonresidential(), 'spalen')))->toEqualCanonicalizing([100297441, 100297442, 100297443]);
});

it('sorts by distance to the given point', function () {
    $field = Address::make('address_id')->near(47.3779, 8.5403);

    expect(array_key_first(fieldSearch($field, 'strasse')))->toBeIn([200000001, 200000002]);

    $field = Address::make('address_id')->near(fn (): array => [47.5565, 7.5757]);

    // "a" matches every fixture street; nearest to Spalenring 113 is itself.
    expect(array_key_first(fieldSearch($field, 'a')))->toBe(100297441);

    $field = Address::make('address_id')->near(47.3779, 8.5403, withinKm: 5);

    expect(fieldSearch($field, 'strasse'))->toHaveCount(2);
});

it('reads the browser position when nearMe() is set', function () {
    $field = Address::make('address_id')->nearMe();
    $field->container(Schema::make(new CreateCustomer)->statePath('data'));

    expect($field->getExtraFieldWrapperAttributes()['x-init'])->toContain("\$wire.\$set('data.address_id__position'");

    $get = new Get($field);
    $field->getContainer()->fill(['address_id__position' => [47.3779, 8.5403]]);

    expect($field->getSearchQuery('strasse', $get, contains: true)->first()->egaid)->toBeIn([200000001, 200000002]);
});

it('creates a customer with a picked address', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Alice', 'address_id' => 100297441])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::first()->address_id)->toBe(100297441)
        ->and(Customer::first()->address_text)->toBeNull();
});

it('rejects an address id that does not exist', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Alice', 'address_id' => 999])
        ->call('create')
        ->assertHasFormErrors(['address_id']);
});

it('stores free text in the custom column and clears it again', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Alice', 'address_id' => 'custom:Somewhere 5, Nowhere'])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::first();

    expect($customer->address_id)->toBeNull()
        ->and($customer->address_text)->toBe('Somewhere 5, Nowhere');

    Livewire::test(EditCustomer::class, ['record' => $customer->getKey()])
        ->assertFormSet(['address_id' => 'custom:Somewhere 5, Nowhere'])
        ->fillForm(['address_id' => 200000001])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->fresh()->address_id)->toBe(200000001)
        ->and($customer->fresh()->address_text)->toBeNull();
});

it('offers the free-text option in search results', function () {
    $component = Livewire::test(CreateCustomer::class);
    $field = $component->instance()->getSchema('form')->getFlatFields()['address_id'];

    $results = $field->getSearchResults('Nowhere 5');

    expect($results)->toHaveKey('custom:Nowhere 5')
        ->and($results['custom:Nowhere 5'])->toBe('Nowhere 5 (free text)');

    $results = $field->getSearchResults('spalen 113');

    expect($results)->toHaveKey('100297441')
        ->and($results['100297441'])->toBe('Spalenring 113, 4055 Basel');
});

it('renders the address column with a map link', function () {
    Customer::create(['name' => 'Alice', 'address_id' => 100297441]);

    Livewire::test(ListCustomers::class)
        ->assertSee('Spalenring 113, 4055 Basel')
        ->assertSee('map.geo.admin.ch');
});

it('offers only existing house numbers in cascade mode', function () {
    $grid = Address::cascade('address_id');
    $schema = Schema::make(new CreateCustomer)->statePath('data')->components([$grid]);
    $schema->fill(['address_id' => 100297442]);

    [$zip, $street, $number] = $grid->getChildSchema()->getComponents();

    expect($schema->getRawState()['address_id__zip'])->toBe('4055 Basel')
        ->and($schema->getRawState()['address_id__street'])->toBe('Spalenring')
        ->and($street->getOptions())->toBe(['Spalenring' => 'Spalenring'])
        ->and($number->getOptions())->toBe(['100297441' => '113', '100297442' => '115']);

    expect($zip->getSearchResults('40'))->toBe(['4054 Basel' => '4054 Basel', '4055 Basel' => '4055 Basel']);
});

it('includes non-residential numbers in cascade mode on request', function () {
    $grid = Address::cascade('address_id', nonresidential: true);
    $schema = Schema::make(new CreateCustomer)->statePath('data')->components([$grid]);
    $schema->fill(['address_id' => 100297442]);

    expect($grid->getChildSchema()->getComponents()[2]->getOptions())->toHaveKey('100297443');
});

it('can find an address by id for the option label', function () {
    $field = Address::make('address_id');
    $field->container(Schema::make(new CreateCustomer)->statePath('data'));
    $field->state(100297441);

    expect($field->getOptionLabel())->toBe('Spalenring 113, 4055 Basel')
        ->and(AddressModel::count())->toBe(7);
});

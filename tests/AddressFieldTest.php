<?php

use Blemli\Swissstreets\Forms\Components\Address;
use Blemli\Swissstreets\Models\Address as AddressModel;
use Blemli\Swissstreets\Tests\Fixtures\CascadeResource\Pages\CreateCascade;
use Blemli\Swissstreets\Tests\Fixtures\Customer;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages\CreateCustomer;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages\ListCustomers;
use Filament\Actions\Testing\TestAction;
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

it('ignores single-character searches and debounces quickly', function () {
    $field = Address::make('address_id');
    $field->container(Schema::make(new CreateCustomer)->statePath('data'));

    expect($field->getSearchResults('s'))->toBe([])
        ->and($field->getSearchResults('sp'))->toHaveKey('100297441')
        ->and($field->getSearchDebounce())->toBe(250);
});

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

it('adds a foreign address as a real row through the create option form', function () {
    $component = Livewire::test(CreateCustomer::class);
    $field = $component->instance()->getSchema('form')->getFlatFields()['address_id'];

    expect($field->allowsFreetext())->toBeTrue()
        ->and($field->getCreateOptionAction())->not->toBeNull();

    $component
        ->fillForm(['name' => 'Alice'])
        ->callAction(TestAction::make('createOption')->schemaComponent('address_id'), data: ['street' => 'Musterweg', 'number' => '7', 'zip' => '12345', 'locality' => 'Berlin', 'country' => 'de'])
        ->assertHasNoActionErrors();

    $berlin = AddressModel::manual()->first();

    expect($berlin)->not->toBeNull()
        ->and($berlin->egaid)->toBe(AddressModel::MANUAL_EGAID_START)
        ->and($berlin->country)->toBe('DE')
        ->and($berlin->line)->toBe('Musterweg 7, DE-12345 Berlin')
        ->and($berlin->egid)->toBeNull()
        ->and($berlin->isForeign())->toBeTrue();

    $component->assertFormSet(['address_id' => $berlin->egaid])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::first()->address_id)->toBe($berlin->egaid)
        ->and(Customer::first()->address->line)->toBe('Musterweg 7, DE-12345 Berlin');
});

it('offers manual rows in the search and validates the country code', function () {
    AddressModel::createManual(['street' => 'Musterweg', 'number' => '7', 'zip' => '12345', 'locality' => 'Berlin', 'country' => 'DE']);
    AddressModel::createManual(['street' => 'Hauptstrasse', 'zip' => '9490', 'locality' => 'Vaduz', 'country' => 'LI']);

    $field = Address::make('address_id');
    $field->container(Schema::make(new CreateCustomer)->statePath('data'));

    expect($field->getSearchResults('musterweg berlin'))->toHaveKey((string) AddressModel::MANUAL_EGAID_START)
        ->and($field->getSearchResults('12345'))->toHaveCount(1)
        ->and($field->getSearchResults('vaduz'))->toHaveCount(1)
        ->and(AddressModel::find(AddressModel::MANUAL_EGAID_START + 1)->line)->toBe('Hauptstrasse, LI-9490 Vaduz');

    Livewire::test(CreateCustomer::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('address_id'), data: ['street' => 'X', 'zip' => '1', 'locality' => 'Y', 'country' => 'Germany'])
        ->assertHasActionErrors(['country']);
});

it('hides the create option unless freetext() is on', function () {
    $field = Address::make('address_id');
    $field->container(Schema::make(new CreateCustomer)->statePath('data'));

    expect($field->allowsFreetext())->toBeFalse()
        ->and($field->getCreateOptionAction()?->isVisible() ?? false)->toBeFalse();
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

    expect($zip->getSearchResults('40'))->toBe(['4054 Basel' => '4054 Basel', '4055 Basel' => '4055 Basel'])
        ->and($zip->getSearchResults('basel'))->toHaveCount(2)
        ->and($zip->getOptions())->toBe([]);
});

it('includes non-residential numbers in cascade mode on request', function () {
    $grid = Address::cascade('address_id')->nonresidential();
    $schema = Schema::make(new CreateCustomer)->statePath('data')->components([$grid]);
    $schema->fill(['address_id' => 100297442]);

    expect($grid->getChildSchema()->getComponents()[2]->getOptions())->toHaveKey('100297443');
});

it('lists the nearest towns first in cascade mode', function () {
    $grid = Address::cascade('address_id')->near(47.3779, 8.5403);
    $schema = Schema::make(new CreateCustomer)->statePath('data')->components([$grid]);
    $schema->fill([]);
    $zip = $grid->getChildSchema()->getComponents()[0];

    expect(array_key_first($zip->getOptions()))->toBe('8001 Zürich')
        // Basel is ~75 km from Zürich HB, Belp ~95 km.
        ->and(array_keys($zip->getSearchResults('b')))->toEqualCanonicalizing(['4054 Basel', '4055 Basel', '3123 Belp'])
        ->and(array_key_last($zip->getSearchResults('b')))->toBe('3123 Belp');
});

it('reads the browser position in cascade mode', function () {
    $grid = Address::cascade('address_id')->nearMe();
    $schema = Schema::make(new CreateCustomer)->statePath('data')->components([$grid]);
    $schema->fill(['address_id__position' => [47.5565, 7.5757]]);

    expect($grid->getExtraAttributes()['x-init'])->toContain("\$wire.\$set('data.address_id__position'")
        ->and(array_key_first($grid->getChildSchema()->getComponents()[0]->getOptions()))->toBe('4055 Basel');
});

it('adds an unlisted house number through the cascade with freetext()', function () {
    $component = Livewire::test(CreateCascade::class)
        ->fillForm(['name' => 'Alice'])
        ->set('data.address_id__zip', '4055 Basel')
        ->set('data.address_id__street', 'Spalenring')
        ->callAction(TestAction::make('createOption')->schemaComponent('address_id'), data: ['number' => '999', 'country' => 'CH'])
        ->assertHasNoActionErrors();

    $new = AddressModel::manual()->first();

    expect($new->line)->toBe('Spalenring 999, 4055 Basel')
        ->and($new->country)->toBe('CH')
        ->and($new->isForeign())->toBeFalse();

    $component->assertFormSet(['address_id' => $new->egaid, 'address_id__zip' => '4055 Basel', 'address_id__street' => 'Spalenring'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::first()->address_id)->toBe($new->egaid);
});

it('adds a foreign address from the town step of the cascade', function () {
    $component = Livewire::test(CreateCascade::class)
        ->fillForm(['name' => 'Bob'])
        ->callAction(TestAction::make('createOption')->schemaComponent('address_id__zip'), data: ['street' => 'Musterweg', 'number' => '7', 'zip' => '12345', 'locality' => 'Berlin', 'country' => 'DE'])
        ->assertHasNoActionErrors();

    $berlin = AddressModel::manual()->first();

    $component->assertFormSet(['address_id__zip' => '12345 Berlin', 'address_id__street' => 'Musterweg', 'address_id' => $berlin->egaid]);

    expect($berlin->line)->toBe('Musterweg 7, DE-12345 Berlin');
});

it('hides the cascade create option unless freetext() is on', function () {
    $grid = Address::cascade('address_id');
    $schema = Schema::make(new CreateCustomer)->statePath('data')->components([$grid]);
    $schema->fill([]);

    foreach ($grid->getChildSchema()->getComponents() as $select) {
        expect($select->getCreateOptionAction()?->isVisible() ?? false)->toBeFalse();
    }
});

it('can find an address by id for the option label', function () {
    $field = Address::make('address_id');
    $field->container(Schema::make(new CreateCustomer)->statePath('data'));
    $field->state(100297441);

    expect($field->getOptionLabel())->toBe('Spalenring 113, 4055 Basel')
        ->and(AddressModel::count())->toBe(8);
});

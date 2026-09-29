<?php

use Blemli\Swissstreets\Forms\Components\MapPicker;
use Blemli\Swissstreets\Tests\Fixtures\Shoot;
use Blemli\Swissstreets\Tests\Fixtures\ShootResource\Pages\CreateShoot;
use Blemli\Swissstreets\Tests\Fixtures\ShootResource\Pages\EditShoot;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentAsset;
use Livewire\Livewire;

beforeEach(function () {
    importFixture();
    loginUser();
    bootPanel();
});

it('renders the map as an async Alpine component with the register search', function () {
    Livewire::test(CreateShoot::class)
        ->assertSeeHtml('fi-fo-map-picker')
        ->assertSeeHtml('wmts.geo.admin.ch')
        ->assertSeeHtml('x-load="visible"')
        ->assertSeeHtml(FilamentAsset::getAlpineComponentSrc('map-picker', 'blemli/swissstreets-for-filament'))
        ->assertSeeHtml('x-load-css')
        ->assertSeeHtml('leaflet.css')
        ->assertSeeHtml('map-picker.css')
        ->assertSeeHtml('x-data="mapPicker(')
        ->assertDontSeeHtml('window.swissstreetsMapPicker')
        ->assertSee('Click the map or search an address');
});

it('ships Leaflet and the picker styles as published assets', function () {
    expect(file_get_contents(__DIR__ . '/../resources/dist/map-picker.js'))->toContain('leaflet')
        ->and(file_get_contents(__DIR__ . '/../resources/dist/map-picker.css'))->toContain('.dark .fi-fo-map-picker-map')
        ->and(file_get_contents(__DIR__ . '/../resources/dist/leaflet.css'))->toContain('.leaflet-container');
});

it('boots the picker inside a select\'s create-option modal, where inline scripts would be inert', function () {
    Livewire::test(CreateShoot::class)
        ->mountFormComponentAction('nearby', 'createOption')
        ->assertSeeHtml('x-data="mapPicker(')
        ->assertSeeHtml(FilamentAsset::getAlpineComponentSrc('map-picker', 'blemli/swissstreets-for-filament'))
        ->assertDontSeeHtml('<script>');
});

it('saves a free pin into the latitude and longitude columns', function () {
    Livewire::test(CreateShoot::class)
        ->fillForm(['name' => 'Field', 'location' => ['lat' => 46.95, 'lng' => 7.44, 'search' => null]])
        ->call('create')
        ->assertHasNoFormErrors();

    $shoot = Shoot::first();

    expect($shoot->latitude)->toBe(46.95)
        ->and($shoot->longitude)->toBe(7.44)
        ->and($shoot->address_id)->toBeNull();
});

it('jumps to a register address and stores its id', function () {
    Livewire::test(CreateShoot::class)
        ->fillForm(['name' => 'Studio'])
        ->set('data.location.search', 100297441)
        ->assertFormSet(function (array $state): void {
            expect($state['location']['lat'])->toEqualWithDelta(47.5565, 0.001)
                ->and($state['location']['lng'])->toEqualWithDelta(7.5757, 0.001);
        })
        ->call('create')
        ->assertHasNoFormErrors();

    $shoot = Shoot::first();

    expect($shoot->address_id)->toBe(100297441)
        ->and($shoot->latitude)->toEqualWithDelta(47.5565, 0.001);
});

it('hydrates the pin from the record and clears it again', function () {
    $shoot = Shoot::create(['name' => 'Lake', 'latitude' => 46.5, 'longitude' => 6.6, 'address_id' => null]);

    Livewire::test(EditShoot::class, ['record' => $shoot->getKey()])
        ->assertFormSet(['location' => ['lat' => 46.5, 'lng' => 6.6, 'search' => null]])
        ->fillForm(['location' => ['lat' => null, 'lng' => null, 'search' => null]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($shoot->fresh()->latitude)->toBeNull()
        ->and($shoot->fresh()->longitude)->toBeNull();
});

it('can hide the search and change map defaults', function () {
    $field = MapPicker::make('location')->search(false)->height('10rem')->zoom(12)->center(47.0, 8.0);
    $field->container(Schema::make(new CreateShoot)->statePath('data'));

    expect($field->hasSearch())->toBeFalse()
        ->and($field->getHeight())->toBe('10rem')
        ->and($field->getZoom())->toBe(12)
        ->and($field->getCenter())->toBe([47.0, 8.0])
        ->and($field->getChildComponents())->toBe([]);
});

it('does not leak the search key into the record', function () {
    Livewire::test(CreateShoot::class)
        ->fillForm(['name' => 'X', 'location' => ['lat' => 1.0, 'lng' => 2.0, 'search' => 100297441]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Shoot::first()->address_id)->toBe(100297441)
        ->and(Shoot::first()->getAttributes())->not->toHaveKey('location');
});

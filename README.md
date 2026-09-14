# swissstreets

Every Swiss address. Offline.

![swissstreets](art/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blemli/swissstreets-for-filament.svg?style=flat-square)](https://packagist.org/packages/blemli/swissstreets-for-filament) [![Tests](https://img.shields.io/github/actions/workflow/status/blemli/swissstreets-for-filament/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/blemli/swissstreets-for-filament/actions?query=workflow%3Atests+branch%3Amain) [![Code Style](https://img.shields.io/github/actions/workflow/status/blemli/swissstreets-for-filament/fix-code-style.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/blemli/swissstreets-for-filament/actions?query=workflow%3Afix-code-style+branch%3Amain) [![Total Downloads](https://img.shields.io/packagist/dt/blemli/swissstreets-for-filament.svg?style=flat-square)](https://packagist.org/packages/blemli/swissstreets-for-filament)

Address field for Filament backed by the official Swiss building address register (swisstopo / Bundesamt für Landestopografie). Nightly update from the official source, works fully offline, sorts by vicinity — no spatial extension required.

## Install

```bash
composer require blemli/swissstreets-for-filament
php artisan swissstreets-for-filament:install
php artisan swissstreets:import      # ~2M addresses, runs nightly afterwards
```

## Use

```php
// Panel
->plugin(SwissstreetsPlugin::make()->cantons(['BS', 'BL'])->notify(User::class))

// Model
class Customer extends Model { use HasAddress; }   // needs an address_id column

// Form: "spalen 113" → Spalenring 113, 4055 Basel
Address::make('address_id')->nonresidential()->nearMe()->allowCustom('address_text')
Address::cascade('address_id')                     // ZIP → street → existing house numbers only

// Table / infolist
AddressColumn::make('address')->map()
AddressEntry::make('address')->map()

// Query
Address::search('bahnhof zürich')->near($lat, $lng, withinKm: 5)->used()
```

Plugin options: `->swissgrid()` keeps LV95 easting/northing, `->unofficial()`, `->planned()`, `->schedule('03:00')`, `->resource(false)`. Removed addresses are soft-deleted, logged and recorded with spatie/laravel-activitylog when installed. Remove everything with `php artisan swissstreets:uninstall`.

MIT © [blemli](https://github.com/blemli)

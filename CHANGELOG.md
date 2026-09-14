# Changelog

All notable changes to `swissstreets-for-filament` will be documented in this file.

## v0.1.0 - 2026-09-14

- `Address` form field: searchable select over the official Swiss address register, `->nonresidential()`, `->near()`, `->nearMe()`, `->allowCustom()`
- `Address::cascade()`: ZIP/town → street → existing house numbers only
- `AddressColumn` and `AddressEntry` with map.geo.admin.ch link
- Read-only "Addresses" resource with used/residential/canton/category/removed filters
- Importer: streamed CSV out of the swisstopo zip, LV95 → WGS84, official/real/decimal-free scope, cantons, skip when unchanged, `--file` for offline imports, nightly schedule
- Added/removed addresses are soft-deleted, logged and recorded with spatie/laravel-activitylog
- `HasAddress` trait, `used()` / `near()` / `search()` scopes
- Import summary as Filament database notification
- German and English translations
- `swissstreets:import` and `swissstreets:uninstall` commands

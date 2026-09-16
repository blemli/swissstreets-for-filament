# Changelog

All notable changes to `swissstreets-for-filament` will be documented in this file.

## v0.3.0 - 2026-09-16

- Case- and accent-insensitive search on every driver: normalised `street_search`, `locality_search`, `commune_search` columns ("Zürich" → "zurich", "Écublens" → "ecublens") matched with indexed range comparisons and native `instr()`/`strpos()` — no custom SQL functions, no PHP `LIKE`
- Schema change: re-publish the migration (or add the three columns) and run `swissstreets:import --force` to backfill

## v0.2.1 - 2026-09-16

- Type-ahead: search debounce 250 ms instead of Filament's 1000 ms, single characters ignored, later words matched with native `instr()` on SQLite — every keystroke now costs one short round trip

## v0.2.0 - 2026-09-15

- `MapPicker` form field: Leaflet map with swisstopo tiles, register search jumps the pin, click or drag places it anywhere, writes latitude/longitude (and optionally the address id); dark mode aware; tiles and Leaflet URLs configurable for offline hosting
- Search: first word matched via index-friendly range comparisons, `ANALYZE` after import, no index on `category` — searches on 2M rows dropped from seconds to milliseconds on SQLite
- `Address` field falls back to a contains match when no word starts with the input

Browser QA (Chrome, fotimo shoot form): map renders with swisstopo tiles ✅ · click moves pin + coordinates ✅ ·
register search jumps pin ✅ · save writes latitude/longitude ✅ · reload hydrates pin ✅ · dark mode ✅ ·
search dropdown above the map ✅ · console errors 0 ✅

## v0.1.0 - 2026-09-14

- `Address` form field: searchable select over the official Swiss address register, `->nonresidential()`, `->near()`, `->nearMe()`, `->allowCustom()`
- `Address::cascade()`: ZIP/town → street → existing house numbers only
- `AddressColumn` and `AddressEntry` with map.geo.admin.ch link
- Read-only "Addresses" resource with used/residential/canton/category/removed filters
- Importer: streamed CSV out of the swisstopo zip, LV95 → WGS84, official/real/decimal-free scope, cantons, skip when unchanged, `--file` for offline imports, nightly schedule
- Added/removed addresses are soft-deleted, logged and recorded with spatie/laravel-activitylog
- `HasAddress` trait, `used()` / `near()` / `search()` scopes
- Import summary as Filament database notification
- Translations: German, French, Italian, Romansh and English
- `swissstreets:import` and `swissstreets:uninstall` commands

Browser QA (Chrome, fotimo host with the full 2.08M-row register on SQLite):
pick address ✅ · free-text fallback ✅ · state round-trip (edit/view/table) ✅ ·
dark mode ✅ · light mode ✅ · keyboard (tab, type, arrow, enter) ✅ ·
addresses resource with used/canton/residential/removed filters + multi-word search ✅ ·
empty states ✅ · console errors 0 ✅ · failing requests 0 ✅ ·
`->nearMe()` not verifiable (geolocation denied in the test profile) ⚠️

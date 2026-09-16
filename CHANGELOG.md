# Changelog

All notable changes to `swissstreets-for-filament` will be documented in this file.

## Unreleased

- README: "Supported plugins" section (spatie/laravel-health, spatie/laravel-activitylog).
- spatie/laravel-health check `Blemli\Swissstreets\Health\AddressRegisterCheck`: red when no address was created, updated or removed for 21 days, yellow when the last 3 imports failed in a row. Enable with `SwissstreetsPlugin::make()->health()` or the `health.enabled` config key; the installer offers it when spatie/laravel-health is installed. Thresholds `health.max_age_days` / `health.max_failed_runs` (or `->health(maxAgeDays:, maxFailedRuns:)`). Every import run now records its outcome (`Import\ImportStatus`).

- Import: transient network errors against swisstopo (connection reset, DNS hiccup) are retried with backoff (2 s / 5 s / 10 s) on both the version probe and the download; a failing probe no longer aborts the run, it just downloads. Errors read "Could not reach swisstopo (data.geo.admin.ch): … run again: php artisan swissstreets:import" instead of raw cURL text, on the console and in the panel notification. A failed download leaves no `register.csv.zip.part` behind.
- Install: a failed first import makes `swissstreets:install` exit non-zero and print the command to run again.
- Import lock: `swissstreets:import --unlock` releases a lock left behind by a killed run (and the scheduler's `withoutOverlapping` mutex). The refusal names the holder ("running since 14:03, PID 4711 on host, started from the command line"); a lock whose process died on the same host is released automatically. Ctrl-C / SIGTERM now release the lock and delete the half download. `swissstreets:uninstall` forgets lock and version stamp. Lock TTL 4 h (was 2 h). New `Import\ImportLock` and `Import\ImportFailed`.

## v0.8.0 - 2026-09-16

- `Address::cascade('address_id')` now returns an `AddressCascade` with the same features as the single field: `->nearMe()` / `->near()` list the nearest towns first (and on open, before typing), `->nonresidential()`, and `->freetext()` puts the add-address form on every step — an unlisted house number (or street, or a foreign town) becomes a manual register row and is selected in all three selects.
- The second positional argument of `cascade()` is gone; use `->nonresidential()`.

## v0.7.0 - 2026-09-16

- Laravel events: `AddressAdded`, `AddressRemoved`, `AddressRestored` (per address, from the import or the field's + form; not per row on the very first full import) and `ImportFinished` (carries the `ImportResult`, dispatched after every run) under `Blemli\Swissstreets\Events`
- Install command renamed to `swissstreets:install` (was `swissstreets-for-filament:install`), matching `swissstreets:import` and `swissstreets:uninstall`

## v0.6.1 - 2026-09-16

Map picker pin is a heroicon in the panel's primary colour with a shadow (dark mode aware); the installer asks whether to run the import right away.

## v0.6.0 - 2026-09-16

Foreign and unlisted addresses are real register rows: Address::make()->freetext() adds a + action with a small form (street, number, ZIP, town, country) creating a source=manual row; they show in the addresses table and survive imports. Schema: zip is text, egid/lat/lng/canton nullable, new country and source columns.

## v0.5.1 - 2026-09-16

- No notification when the nightly import finds the register unchanged

## v0.5.0 - 2026-09-16

- The addresses table in the panel is opt-in: `SwissstreetsPlugin::make()->table()` (config key `table`). `->resource(false)` and the `resource` key are gone.
- Config key for the database table renamed from `table` to `table_name`.

## v0.4.0 - 2026-09-16

- The nightly import is no longer registered automatically. The installer asks for a time and writes a marked `Schedule::command('swissstreets:import')` block into `routes/console.php`; the uninstaller removes it. `->schedule()` and the `schedule` config key are gone.

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

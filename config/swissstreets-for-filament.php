<?php

// config for Blemli/Swissstreets
return [

    /*
    |--------------------------------------------------------------------------
    | Source
    |--------------------------------------------------------------------------
    | The official building address register (Amtliches Gebäudeadressverzeichnis)
    | published by swisstopo as a zipped CSV in LV95 coordinates.
    */
    'source_url' => 'https://data.geo.admin.ch/ch.swisstopo.amtliches-gebaeudeadressverzeichnis/amtliches-gebaeudeadressverzeichnis_ch/amtliches-gebaeudeadressverzeichnis_ch_2056.csv.zip',

    // Where the downloaded zip is kept between runs (relative to storage_path()).
    'storage_path' => 'app/swissstreets',

    /*
    |--------------------------------------------------------------------------
    | Import scope
    |--------------------------------------------------------------------------
    */
    // Restrict to these cantons (['BS', 'BL']); empty = whole country (~2M rows).
    'cantons' => [],

    // Also import addresses flagged ADR_OFFICIAL=false.
    'unofficial' => false,

    // Also import addresses with ADR_STATUS=planned.
    'planned' => false,

    // Keep the original LV95 easting/northing next to lat/lng.
    'swissgrid' => false,

    // BDG_CATEGORY values the address field offers unless ->nonresidential() is set.
    'residential_categories' => ['residential', 'other_residential', 'partly_residential'],

    // Rows per upsert statement. SQLite needs (rows × columns) below its bind limit.
    'chunk_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Schedule & logging
    |--------------------------------------------------------------------------
    */
    // Nightly import time (HH:MM); null disables the schedule.
    'schedule' => '03:00',

    // Log channel for added/removed addresses; null = default channel.
    'log_channel' => null,

    // Whether to log every added address on the very first (full) import.
    'log_initial_import' => false,

    // Record changes with spatie/laravel-activitylog when it is installed.
    'activitylog' => true,

    // Filament database notification after each import: null, a user model
    // class (notifies all), or a callable returning users / a collection.
    'notify' => null,

    /*
    |--------------------------------------------------------------------------
    | Panel
    |--------------------------------------------------------------------------
    */
    // Register the read-only "Addresses" resource in the panel.
    'resource' => true,

    'navigation_group' => null,

    'navigation_sort' => null,

    /*
    |--------------------------------------------------------------------------
    | Map picker
    |--------------------------------------------------------------------------
    | Tiles and Leaflet are fetched from the network; point these at
    | self-hosted copies for a fully offline panel.
    */
    'map' => [
        'tiles' => 'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.pixelkarte-farbe/default/current/3857/{z}/{x}/{y}.jpeg',
        'attribution' => '&copy; <a href="https://www.swisstopo.admin.ch" target="_blank" rel="noopener">swisstopo</a>',
        'leaflet_js' => 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
        'leaflet_css' => 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css',
    ],

    // Table name of the address register.
    'table' => 'swissstreets_addresses',
];

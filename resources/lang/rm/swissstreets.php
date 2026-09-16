<?php

// Rumantsch Grischun
return [
    'address' => 'Adressa',
    'addresses' => 'Adressas',
    'field' => [
        'placeholder' => 'Tschertgar via, numer, NPA u lieu',
        'no_results' => 'Nagina adressa chattada',
        'custom' => ':text (text liber)',
        'zip' => 'NPA / Lieu',
        'street' => 'Via',
        'number' => 'Nr.',
    ],
    'columns' => [
        'egaid' => 'ID da l’adressa',
        'egid' => 'EGID',
        'street' => 'Via',
        'number' => 'Nr.',
        'zip' => 'NPA',
        'locality' => 'Lieu',
        'commune' => 'Vischnanca',
        'canton' => 'Chantun',
        'category' => 'Categoria',
        'lat' => 'Latitudine',
        'lng' => 'Longitudine',
        'easting' => 'Ost (LV95)',
        'northing' => 'Nord (LV95)',
        'imported_at' => 'Vis l’ultima giada',
        'deleted_at' => 'Allontanada',
        'usages' => 'Duvrada',
    ],
    'filters' => [
        'used' => 'Mo duvradas',
        'residential' => 'Mo adressas d’abitar',
        'trashed' => 'Adressas allontanadas',
    ],
    'categories' => [
        'residential' => 'Chasa d’abitar',
        'other_residential' => 'Autra chasa d’abitar',
        'partly_residential' => 'Per part abitada',
        'non_residential' => 'Senza abitaziun',
        'special' => 'Edifizi spezial',
        'temporary' => 'Provisoric',
    ],
    'map' => 'Mussar sin la charta',
    'notification' => [
        'title' => 'Import da las adressas terminà',
        'body' => ':added novas, :removed allontanadas, :restored restauradas en :duration.',
        'failed' => 'Import da las adressas betg reussì',
    ],
    'map_picker' => [
        'label' => 'Lieu',
        'hint' => 'Cliccar sin la charta u tschertgar in’adressa per plazzar il pin.',
        'clear' => 'Allontanar il pin',
    ],
    'activity' => [
        'added' => 'Il sistem ha agiuntà l’adressa',
        'removed' => 'Il sistem ha allontanà l’adressa',
        'restored' => 'Il sistem ha restaurà l’adressa',
    ],
];

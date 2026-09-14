<?php

it('ships the same keys in German and English', function () {
    $en = require __DIR__ . '/../resources/lang/en/swissstreets.php';
    $de = require __DIR__ . '/../resources/lang/de/swissstreets.php';

    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($array as $key => $value) {
            $keys = is_array($value)
                ? [...$keys, ...$flatten($value, "{$prefix}{$key}.")]
                : [...$keys, "{$prefix}{$key}"];
        }

        return $keys;
    };

    expect($flatten($de))->toEqualCanonicalizing($flatten($en));
});

it('translates the address label', function () {
    expect(__('swissstreets-for-filament::swissstreets.address'))->toBe('Address');

    app()->setLocale('de');

    expect(__('swissstreets-for-filament::swissstreets.address'))->toBe('Adresse');
});

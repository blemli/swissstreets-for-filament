<?php

it('ships the same keys in every language', function (string $locale) {
    $en = require __DIR__ . '/../resources/lang/en/swissstreets.php';
    $de = require __DIR__ . "/../resources/lang/{$locale}/swissstreets.php";

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
})->with(['de', 'fr', 'it', 'rm']);

it('translates the address label', function () {
    expect(__('swissstreets-for-filament::swissstreets.address'))->toBe('Address');

    foreach (['de' => 'Adresse', 'fr' => 'Adresse', 'it' => 'Indirizzo', 'rm' => 'Adressa'] as $locale => $label) {
        app()->setLocale($locale);

        expect(__('swissstreets-for-filament::swissstreets.address'))->toBe($label);
    }
});

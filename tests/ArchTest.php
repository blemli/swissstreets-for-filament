<?php

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

it('keeps console output untranslated', function () {
    foreach (glob(__DIR__ . '/../src/Commands/*.php') ?: [] as $file) {
        expect(file_get_contents($file))->not->toContain('__(');
    }
});

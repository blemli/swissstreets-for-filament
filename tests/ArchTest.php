<?php

use Blemli\Swissstreets\Import\Downloader;
use Blemli\Swissstreets\Import\Importer;
use Blemli\Swissstreets\Swissstreets;
use Illuminate\Contracts\Queue\ShouldQueue;

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

it('keeps console output untranslated', function () {
    foreach (glob(__DIR__ . '/../src/Commands/*.php') ?: [] as $file) {
        expect(file_get_contents($file))->not->toContain('__(');
    }
});

it('sends every HTTP request through the Http facade so retries and fakes apply everywhere')
    ->expect('Blemli\Swissstreets')
    ->not->toUse(['GuzzleHttp\Client', 'curl_init', 'file_get_contents']);

it('creates the import lock in exactly one class', function () {
    $owners = [];

    foreach (glob(__DIR__ . '/../src/**/*.php') ?: [] as $file) {
        if (preg_match('/(Cache::|->)lock\(/', file_get_contents($file))) {
            $owners[] = basename($file);
        }
    }

    expect($owners)->toBe(['ImportLock.php']);
});

it('keeps spatie/laravel-health confined to the Health namespace')
    ->expect('Blemli\\Swissstreets')
    ->not->toUse('Spatie\\Health')
    ->ignoring('Blemli\\Swissstreets\\Health');

it('keeps spatie/laravel-activitylog behind the availability guard')
    ->expect('Blemli\\Swissstreets')
    ->not->toUse('Spatie\\Activitylog')
    ->ignoring([Swissstreets::class, Importer::class]);

it('ships the resource concerns as traits')
    ->expect('Blemli\\Swissstreets\\Resources\\Concerns')
    ->toBeTraits();

it('has no hard-coded nightly time left', function () {
    foreach (glob(__DIR__ . '/../src/**/*.php') ?: [] as $file) {
        expect(file_get_contents($file))->not->toContain("'03:00'");
    }
});

it('never runs the import inside a panel request')
    ->expect('Blemli\\Swissstreets\\Resources')
    ->not->toUse([Importer::class, Downloader::class]);

it('queues every job')
    ->expect('Blemli\\Swissstreets\\Jobs')
    ->toImplement(ShouldQueue::class);

it('keeps Filament\'s CSV importer out of the package')
    ->expect('Blemli\\Swissstreets')
    ->not->toUse('Filament\\Actions\\ImportAction');

it('never uses the default-no migration prompt and gives every confirm an explicit default', function () {
    foreach (glob(__DIR__ . '/../src/**/*.php') ?: [] as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toContain('askToRunMigrations(');

        preg_match_all('/confirm\((?:[^()]|\([^()]*\))*\)/', $source, $matches);

        foreach ($matches[0] as $call) {
            expect($call)->toMatch('/true|false|default:/', "{$file}: {$call}");
        }
    }
});

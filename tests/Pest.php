<?php

use Blemli\Swissstreets\Import\Importer;
use Blemli\Swissstreets\Import\ImportLock;
use Blemli\Swissstreets\Import\ImportResult;
use Blemli\Swissstreets\Tests\Fixtures\User;
use Blemli\Swissstreets\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Cache;

uses(TestCase::class)->in(__DIR__);

function fixturePath(string $name): string
{
    return __DIR__ . '/Fixtures/' . $name;
}

function importFixture(string $name = 'register.csv'): ImportResult
{
    return app(Importer::class)->run(fixturePath($name));
}

function bootPanel(string $id = 'admin'): Panel
{
    $panel = Filament::getPanel($id);

    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();

    return $panel;
}

function loginUser(): User
{
    $user = User::create([
        'name' => 'Test User',
        'email' => uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    test()->actingAs($user);

    return $user;
}

/** Simulate a lock left behind by another (possibly dead) import process. */
function holdImportLock(array $meta): void
{
    Cache::lock(ImportLock::KEY, 60)->get();
    Cache::put(ImportLock::META_KEY, $meta + ['started_at' => '2026-09-16T14:03:00+02:00', 'trigger' => 'cli'], 60);
}

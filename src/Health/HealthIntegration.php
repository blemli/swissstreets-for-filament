<?php

namespace Blemli\Swissstreets\Health;

/**
 * The only place outside the check that talks to spatie/laravel-health, so the
 * package boots without it. Mirrors Swissstreets::activitylogAvailable().
 */
class HealthIntegration
{
    public const FACADE = 'Spatie\Health\Facades\Health';

    public static function installed(): bool
    {
        return class_exists(self::FACADE);
    }

    public static function enabled(): bool
    {
        return (bool) config('swissstreets-for-filament.health.enabled', false) && self::installed();
    }

    /** Register the check once the app has booted (plugin options are set by then). */
    public static function register(): void
    {
        if (! self::enabled()) {
            return;
        }

        $facade = self::FACADE;

        $registered = collect($facade::registeredChecks())
            ->contains(fn ($check): bool => $check instanceof AddressRegisterCheck);

        if (! $registered) {
            $facade::checks([AddressRegisterCheck::new()]);
        }
    }
}

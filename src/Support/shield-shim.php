<?php

namespace BezhanSalleh\FilamentShield\Contracts;

// Filament Shield only honours custom permissions on resources that implement
// its own interface. Without Shield installed that interface does not exist and
// AddressResource could not implement it — this stand-in fills the gap and steps
// aside as soon as Shield's autoloader provides the real one.
if (! interface_exists(HasShieldPermissions::class)) {
    interface HasShieldPermissions
    {
        /**
         * @return array<string>
         */
        public static function getPermissionPrefixes(): array;
    }
}

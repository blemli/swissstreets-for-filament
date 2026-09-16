<?php

namespace Blemli\Swissstreets\Import;

use Blemli\Swissstreets\Geo\Lv95;
use Blemli\Swissstreets\Models\Address;
use Carbon\CarbonImmutable;

/**
 * Turns one CSV row into a table row, or null when the import scope excludes it.
 */
class RowMapper
{
    /** @var array<int, string> */
    protected array $cantons;

    protected bool $unofficial;

    protected bool $planned;

    protected bool $swissgrid;

    public function __construct()
    {
        $this->cantons = array_map('strtoupper', (array) config('swissstreets-for-filament.cantons', []));
        $this->unofficial = (bool) config('swissstreets-for-filament.unofficial', false);
        $this->planned = (bool) config('swissstreets-for-filament.planned', false);
        $this->swissgrid = (bool) config('swissstreets-for-filament.swissgrid', false);
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    public function map(array $row, string $importedAt, string $timestamp): ?array
    {
        if ($this->cantons !== [] && ! in_array(strtoupper($row['COM_CANTON'] ?? ''), $this->cantons, true)) {
            return null;
        }

        if (! $this->unofficial && ($row['ADR_OFFICIAL'] ?? '') !== 'true') {
            return null;
        }

        if (! $this->planned && ($row['ADR_STATUS'] ?? '') !== 'real') {
            return null;
        }

        $number = trim($row['ADR_NUMBER'] ?? '');

        // "31.1" is a secondary entrance of no. 31 — out of scope.
        if (str_contains($number, '.')) {
            return null;
        }

        if (! preg_match('/^(\d{4}) (.+)$/', trim($row['ZIP_LABEL'] ?? ''), $zip)) {
            return null;
        }

        $easting = (float) ($row['ADR_EASTING'] ?? 0);
        $northing = (float) ($row['ADR_NORTHING'] ?? 0);

        if ($easting < 2_400_000 || $northing < 1_000_000) {
            return null;
        }

        [$lat, $lng] = Lv95::toWgs84($easting, $northing);

        $modified = null;

        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $row['ADR_MODIFIED'] ?? '')) {
            $modified = CarbonImmutable::createFromFormat('d.m.Y', $row['ADR_MODIFIED'])?->toDateString();
        }

        return [
            'egaid' => (int) $row['ADR_EGAID'],
            'egid' => (int) $row['BDG_EGID'],
            'street' => $street = trim($row['STN_LABEL']),
            'number' => $number === '' ? null : $number,
            'number_int' => preg_match('/^(\d+)/', $number, $m) ? (int) $m[1] : null,
            'zip' => $zip[1],
            'locality' => $locality = trim($zip[2]),
            'commune' => $commune = trim($row['COM_NAME']),
            'street_search' => Address::searchKey($street),
            'locality_search' => Address::searchKey($locality),
            'commune_search' => Address::searchKey($commune),
            'canton' => strtoupper(trim($row['COM_CANTON'])),
            'country' => 'CH',
            'source' => Address::SOURCE_REGISTER,
            'category' => trim($row['BDG_CATEGORY']) ?: 'unknown',
            'lat' => $lat,
            'lng' => $lng,
            'easting' => $this->swissgrid ? $easting : null,
            'northing' => $this->swissgrid ? $northing : null,
            'modified_at' => $modified,
            'imported_at' => $importedAt,
            'updated_at' => $timestamp,
            'created_at' => $timestamp,
            'deleted_at' => null,
        ];
    }
}

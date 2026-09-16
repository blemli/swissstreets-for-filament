<?php

namespace Blemli\Swissstreets\Geo;

use Blemli\Swissstreets\Models\Address;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vicinity queries in plain SQL — bounding box on the indexed lat/lng columns,
 * then a haversine ORDER BY. No spatial extension required.
 */
final class Distance
{
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * @param  Builder<Address>  $query
     * @return Builder<Address>
     */
    public static function apply(Builder $query, float $lat, float $lng, ?float $withinKm = null): Builder
    {
        $table = $query->getModel()->getTable();

        if ($withinKm !== null) {
            $dLat = $withinKm / 111.32;
            $dLng = $withinKm / (111.32 * max(cos(deg2rad($lat)), 0.01));

            $query->whereBetween("{$table}.lat", [$lat - $dLat, $lat + $dLat])
                ->whereBetween("{$table}.lng", [$lng - $dLng, $lng + $dLng]);
        }

        // Equirectangular approximation: monotonic with true distance at the
        // scale of a country, needs only sqrt/cos which every driver has.
        $cosLat = cos(deg2rad($lat));
        $expr = "(({$table}.lat - ?) * ({$table}.lat - ?)) + (({$table}.lng - ?) * ({$table}.lng - ?) * ?)";

        return $query->orderByRaw("({$table}.lat is null)")->orderByRaw($expr, [$lat, $lat, $lng, $lng, $cosLat * $cosLat]);
    }

    public static function kilometres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round(2 * self::EARTH_RADIUS_KM * asin(sqrt($a)), 3);
    }
}

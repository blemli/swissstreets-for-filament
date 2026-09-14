<?php

namespace Blemli\Swissstreets\Geo;

/**
 * Swiss LV95 (EPSG:2056) ⇄ WGS84 using swisstopo's approximation formulas.
 * Accuracy is around one metre — plenty for a building address.
 */
final class Lv95
{
    /**
     * @return array{0: float, 1: float} [lat, lng]
     */
    public static function toWgs84(float $easting, float $northing): array
    {
        $y = ($easting - 2_600_000) / 1_000_000;
        $x = ($northing - 1_200_000) / 1_000_000;

        $lng = 2.6779094
            + 4.728982 * $y
            + 0.791484 * $y * $x
            + 0.1306 * $y * $x * $x
            - 0.0436 * $y * $y * $y;

        $lat = 16.9023892
            + 3.238272 * $x
            - 0.270978 * $y * $y
            - 0.002528 * $x * $x
            - 0.0447 * $y * $y * $x
            - 0.0140 * $x * $x * $x;

        return [round($lat * 100 / 36, 6), round($lng * 100 / 36, 6)];
    }

    /**
     * @return array{0: float, 1: float} [easting, northing]
     */
    public static function fromWgs84(float $lat, float $lng): array
    {
        $phi = ($lat * 3600 - 169_028.66) / 10_000;
        $lambda = ($lng * 3600 - 26_782.5) / 10_000;

        $easting = 2_600_072.37
            + 211_455.93 * $lambda
            - 10_938.51 * $lambda * $phi
            - 0.36 * $lambda * $phi * $phi
            - 44.54 * $lambda * $lambda * $lambda;

        $northing = 1_200_147.07
            + 308_807.95 * $phi
            + 3745.25 * $lambda * $lambda
            + 76.63 * $phi * $phi
            - 194.56 * $lambda * $lambda * $phi
            + 119.79 * $phi * $phi * $phi;

        return [round($easting, 3), round($northing, 3)];
    }
}

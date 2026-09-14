<?php

use Blemli\Swissstreets\Geo\Distance;
use Blemli\Swissstreets\Geo\Lv95;

it('converts LV95 to WGS84 within a metre', function () {
    // swisstopo reference point: Bern Zytglogge-ish (E 2600000 / N 1200000 ≈ 46.951083, 7.438637)
    [$lat, $lng] = Lv95::toWgs84(2_600_000, 1_200_000);

    expect($lat)->toEqualWithDelta(46.951083, 0.00002)
        ->and($lng)->toEqualWithDelta(7.438637, 0.00002);
});

it('round-trips coordinates', function () {
    [$lat, $lng] = Lv95::toWgs84(2_683_150, 1_247_500);
    [$e, $n] = Lv95::fromWgs84($lat, $lng);

    expect($e)->toEqualWithDelta(2_683_150, 1.5)
        ->and($n)->toEqualWithDelta(1_247_500, 1.5);
});

it('computes distances in kilometres', function () {
    // Basel SBB → Zürich HB ≈ 74.5 km
    expect(Distance::kilometres(47.5476, 7.5894, 47.3779, 8.5403))->toEqualWithDelta(74.5, 1.0);
});

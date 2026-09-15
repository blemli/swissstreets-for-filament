<?php

namespace Blemli\Swissstreets\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Shoot extends Model
{
    protected $table = 'shoots';

    protected $guarded = [];

    protected $casts = ['latitude' => 'float', 'longitude' => 'float'];
}

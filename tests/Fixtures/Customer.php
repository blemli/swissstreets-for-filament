<?php

namespace Blemli\Swissstreets\Tests\Fixtures;

use Blemli\Swissstreets\Concerns\HasAddress;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasAddress;

    protected $table = 'customers';

    protected $guarded = [];
}

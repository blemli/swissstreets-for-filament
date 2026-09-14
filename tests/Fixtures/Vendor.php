<?php

namespace Blemli\Swissstreets\Tests\Fixtures;

use Blemli\Swissstreets\Concerns\HasAddress;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use HasAddress;

    protected $table = 'vendors';

    protected $guarded = [];

    protected string $addressColumn = 'site_id';
}

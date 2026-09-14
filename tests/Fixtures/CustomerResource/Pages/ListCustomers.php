<?php

namespace Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages;

use Blemli\Swissstreets\Tests\Fixtures\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;
}

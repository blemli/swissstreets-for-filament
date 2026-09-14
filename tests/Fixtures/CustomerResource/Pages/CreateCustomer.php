<?php

namespace Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages;

use Blemli\Swissstreets\Tests\Fixtures\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;
}

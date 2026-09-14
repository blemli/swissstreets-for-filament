<?php

namespace Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages;

use Blemli\Swissstreets\Tests\Fixtures\CustomerResource;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;
}

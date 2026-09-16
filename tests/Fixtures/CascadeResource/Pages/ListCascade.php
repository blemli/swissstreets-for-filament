<?php

namespace Blemli\Swissstreets\Tests\Fixtures\CascadeResource\Pages;

use Blemli\Swissstreets\Tests\Fixtures\CascadeResource;
use Filament\Resources\Pages\ListRecords;

class ListCascade extends ListRecords
{
    protected static string $resource = CascadeResource::class;
}

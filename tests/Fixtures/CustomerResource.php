<?php

namespace Blemli\Swissstreets\Tests\Fixtures;

use Blemli\Swissstreets\Forms\Components\Address;
use Blemli\Swissstreets\Tables\Columns\AddressColumn;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages\CreateCustomer;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages\EditCustomer;
use Blemli\Swissstreets\Tests\Fixtures\CustomerResource\Pages\ListCustomers;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Address::make('address_id')->freetext(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            AddressColumn::make('address')->map(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}

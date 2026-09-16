<?php

namespace Blemli\Swissstreets\Tests\Fixtures;

use Blemli\Swissstreets\Forms\Components\Address;
use Blemli\Swissstreets\Tests\Fixtures\CascadeResource\Pages\CreateCascade;
use Blemli\Swissstreets\Tests\Fixtures\CascadeResource\Pages\ListCascade;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CascadeResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'cascade';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Address::cascade('address_id')->freetext()->nearMe(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCascade::route('/'), 'create' => CreateCascade::route('/create')];
    }
}

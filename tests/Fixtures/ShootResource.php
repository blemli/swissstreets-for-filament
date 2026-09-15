<?php

namespace Blemli\Swissstreets\Tests\Fixtures;

use Blemli\Swissstreets\Forms\Components\MapPicker;
use Blemli\Swissstreets\Tests\Fixtures\ShootResource\Pages\CreateShoot;
use Blemli\Swissstreets\Tests\Fixtures\ShootResource\Pages\EditShoot;
use Blemli\Swissstreets\Tests\Fixtures\ShootResource\Pages\ListShoots;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShootResource extends Resource
{
    protected static ?string $model = Shoot::class;

    protected static ?string $slug = 'shoots';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            MapPicker::make('location')->lat('latitude')->lng('longitude')->address('address_id'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShoots::route('/'),
            'create' => CreateShoot::route('/create'),
            'edit' => EditShoot::route('/{record}/edit'),
        ];
    }
}

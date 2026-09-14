<?php

namespace Blemli\Swissstreets\Tables\Columns;

use Blemli\Swissstreets\Models\Address;
use Closure;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the related address as "Spalenring 113, 4055 Basel".
 * Pass the relation name: AddressColumn::make('address').
 */
class AddressColumn extends TextColumn
{
    protected bool | Closure $hasMapLink = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('swissstreets-for-filament::swissstreets.address'));
        $this->icon(Heroicon::OutlinedMapPin);
        $this->getStateUsing(fn (Model $record): ?string => $this->resolveAddress($record)?->line);
        $this->url(fn (Model $record): ?string => $this->hasMapLink() ? $this->resolveAddress($record)?->mapUrl() : null, shouldOpenInNewTab: true);
    }

    public function map(bool | Closure $condition = true): static
    {
        $this->hasMapLink = $condition;

        return $this;
    }

    public function hasMapLink(): bool
    {
        return (bool) $this->evaluate($this->hasMapLink);
    }

    protected function resolveAddress(Model $record): ?Address
    {
        $value = data_get($record, $this->getName());

        return $value instanceof Address ? $value : null;
    }
}

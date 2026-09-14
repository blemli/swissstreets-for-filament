<?php

namespace Blemli\Swissstreets\Infolists\Components;

use Blemli\Swissstreets\Models\Address;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Infolist counterpart of AddressColumn: AddressEntry::make('address')->map().
 */
class AddressEntry extends TextEntry
{
    protected bool | Closure $hasMapLink = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('swissstreets-for-filament::swissstreets.address'));
        $this->icon(Heroicon::OutlinedMapPin);
        $this->getStateUsing(fn (?Model $record): ?string => $record ? $this->resolveAddress($record)?->line : null);
        $this->url(fn (?Model $record): ?string => ($record && $this->hasMapLink()) ? $this->resolveAddress($record)?->mapUrl() : null, shouldOpenInNewTab: true);
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

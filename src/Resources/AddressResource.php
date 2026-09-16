<?php

namespace Blemli\Swissstreets\Resources;

use BackedEnum;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Blemli\Swissstreets\Import\ImportLock;
use Blemli\Swissstreets\Models\Address;
use Blemli\Swissstreets\Resources\AddressResource\Pages\ListAddresses;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AddressResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Address::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $slug = 'addresses';

    public static function getModelLabel(): string
    {
        return __('swissstreets-for-filament::swissstreets.address');
    }

    public static function getPluralModelLabel(): string
    {
        return __('swissstreets-for-filament::swissstreets.addresses');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('swissstreets-for-filament.navigation_group');
    }

    public static function getNavigationSort(): ?int
    {
        return config('swissstreets-for-filament.navigation_sort');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The "Import" button: an AddressPolicy::import() decides when the app has
     * one (Filament Shield generates it from the prefixes below), else allowed.
     */
    public static function canImport(): bool
    {
        return static::can('import');
    }

    /**
     * Filament Shield: view_any_address, view_address, import_address.
     *
     * @return array<string>
     */
    public static function getPermissionPrefixes(): array
    {
        return ['view_any', 'view', 'import'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key): string => __("swissstreets-for-filament::swissstreets.{$key}");

        $categories = collect(['residential', 'other_residential', 'partly_residential', 'non_residential', 'special', 'temporary', 'manual'])
            ->mapWithKeys(fn (string $c): array => [$c => $t("categories.{$c}")])
            ->all();

        return $table
            ->columns([
                // One multi-word search ("marktgasse 12 bern") over street,
                // number, ZIP and town — the other columns stay non-searchable
                // so Filament does not OR a phrase match onto it.
                TextColumn::make('street')->label($t('columns.street'))->searchable(query: function (Builder $query, string $search): void {
                    /** @var Builder<Address> $query */
                    $query->search($search);
                })->sortable(),
                TextColumn::make('number')->label($t('columns.number'))->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('number_int', $direction)->orderBy('number', $direction)),
                TextColumn::make('zip')->label($t('columns.zip'))->sortable(),
                TextColumn::make('locality')->label($t('columns.locality'))->sortable(),
                TextColumn::make('commune')->label($t('columns.commune'))->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('canton')->label($t('columns.canton'))->sortable()->placeholder('—'),
                TextColumn::make('country')->label($t('columns.country'))->sortable()->toggleable(),
                TextColumn::make('category')->label($t('columns.category'))->badge()->formatStateUsing(fn (string $state): string => $categories[$state] ?? $state)->toggleable(),
                TextColumn::make('source')->label($t('columns.source'))->badge()->color(fn (string $state): string => $state === Address::SOURCE_MANUAL ? 'warning' : 'gray')->formatStateUsing(fn (string $state): string => $state === Address::SOURCE_MANUAL ? $t('categories.manual') : 'swisstopo')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('egaid')->label($t('columns.egaid'))->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('egid')->label($t('columns.egid'))->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lat')->label($t('columns.lat'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lng')->label($t('columns.lng'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('imported_at')->label($t('columns.imported_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')->label($t('columns.deleted_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('street')
            ->filters([
                Filter::make('used')
                    ->label($t('filters.used'))
                    ->toggle()
                    ->default()
                    ->query(function (Builder $query): void {
                        /** @var Builder<Address> $query */
                        $query->used();
                    }),
                Filter::make('residential')
                    ->label($t('filters.residential'))
                    ->toggle()
                    ->query(function (Builder $query): void {
                        /** @var Builder<Address> $query */
                        $query->residential();
                    }),
                SelectFilter::make('canton')
                    ->label($t('columns.canton'))
                    ->multiple()
                    ->options(fn (): array => Address::query()->withTrashed()->whereNotNull('canton')->distinct()->orderBy('canton')->pluck('canton', 'canton')->all()),
                Filter::make('manual')
                    ->label($t('filters.manual'))
                    ->toggle()
                    ->query(function (Builder $query): void {
                        /** @var Builder<Address> $query */
                        $query->manual();
                    }),
                SelectFilter::make('category')
                    ->label($t('columns.category'))
                    ->multiple()
                    ->options($categories),
                TrashedFilter::make()->label($t('filters.trashed')),
            ])
            ->recordActions([
                Action::make('map')
                    ->label($t('map'))
                    ->icon(Heroicon::OutlinedMap)
                    ->url(fn (Address $record): ?string => $record->mapUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (Address $record): bool => $record->mapUrl() !== null),
            ])
            // While an import runs the header button is disabled; poll so it comes back on its own.
            ->poll(fn (): ?string => app(ImportLock::class)->isLocked() ? '15s' : null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAddresses::route('/'),
        ];
    }
}

<?php

namespace Blemli\Swissstreets\Forms\Components;

use Blemli\Swissstreets\Models\Address as AddressModel;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Searchable select over the official Swiss address register. Stores the
 * address id (EGAID) in the given column.
 */
class Address extends Select
{
    protected const CUSTOM_PREFIX = 'custom:';

    protected bool | Closure $isNonResidential = false;

    /** @var array{0: float, 1: float}|Closure|null */
    protected array | Closure | null $near = null;

    protected bool | Closure $isNearMe = false;

    protected float | Closure | null $withinKm = null;

    protected ?string $customColumn = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('swissstreets-for-filament::swissstreets.address'));
        $this->searchable();
        $this->searchPrompt(fn (): string => __('swissstreets-for-filament::swissstreets.field.placeholder'));
        $this->noSearchResultsMessage(fn (): string => __('swissstreets-for-filament::swissstreets.field.no_results'));
        $this->optionsLimit(50);
        $this->native(false);
        // Filament waits a full second by default — far too long for type-ahead.
        $this->searchDebounce(250);

        $this->getSearchResultsUsing(fn (string $search, Get $get): array => $this->searchAddresses($search, $get));
        $this->getOptionLabelUsing(fn (mixed $value): ?string => $this->optionLabel($value));

        $this->afterStateHydrated(function (Address $component, mixed $state, ?Model $record): void {
            if (filled($state) || ! $component->customColumn || ! $record) {
                return;
            }

            $text = $record->getAttribute($component->customColumn);

            if (filled($text)) {
                $component->state(self::CUSTOM_PREFIX . $text);
            }
        });

        $this->dehydrateStateUsing(fn (mixed $state): mixed => self::isCustom($state) ? null : $state);

        $this->saveRelationshipsUsing(function (Address $component, ?Model $record, mixed $state): void {
            if (! $component->customColumn || ! $record) {
                return;
            }

            $text = self::isCustom($state) ? self::customText($state) : null;

            if ($record->getAttribute($component->customColumn) !== $text) {
                $record->forceFill([$component->customColumn => $text])->saveQuietly();
            }
        });
    }

    // ---- configuration -----------------------------------------------------

    /** Offer non-residential buildings (offices, barns, …) too. */
    public function nonresidential(bool | Closure $condition = true): static
    {
        $this->isNonResidential = $condition;

        return $this;
    }

    /**
     * Sort search results by distance to the given point.
     *
     * @param  array{0: float, 1: float}|Closure|float  $latOrPoint
     */
    public function near(array | Closure | float $latOrPoint, ?float $lng = null, ?float $withinKm = null): static
    {
        $this->near = is_float($latOrPoint) ? [$latOrPoint, (float) $lng] : $latOrPoint;
        $this->withinKm = $withinKm;

        return $this;
    }

    /** Sort by distance to the browser's geolocation (asks the user once). */
    public function nearMe(bool | Closure $condition = true): static
    {
        $this->isNearMe = $condition;

        return $this;
    }

    /** Accept free text when nothing matches; stored in the given column. */
    public function allowCustom(string $column = 'address_text'): static
    {
        $this->customColumn = $column;

        return $this;
    }

    public function getCustomColumn(): ?string
    {
        return $this->customColumn;
    }

    public function isNonResidential(): bool
    {
        return (bool) $this->evaluate($this->isNonResidential);
    }

    public function isNearMe(): bool
    {
        return (bool) $this->evaluate($this->isNearMe);
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraFieldWrapperAttributes(): array
    {
        $attributes = parent::getExtraFieldWrapperAttributes();

        if ($this->isNearMe()) {
            $path = $this->getPositionStatePath();
            $attributes['x-init'] = "navigator.geolocation && navigator.geolocation.getCurrentPosition((p) => \$wire.\$set('{$path}', [p.coords.latitude, p.coords.longitude], false), () => {}, { maximumAge: 600000 })";
        }

        return $attributes;
    }

    protected function getPositionStatePath(): string
    {
        return $this->getStatePath() . '__position';
    }

    // ---- data --------------------------------------------------------------

    /**
     * @return array{0: float, 1: float}|null
     */
    protected function resolvePoint(?Get $get): ?array
    {
        $point = $this->evaluate($this->near);

        if (is_array($point) && count($point) === 2 && is_numeric($point[0]) && is_numeric($point[1])) {
            return [(float) $point[0], (float) $point[1]];
        }

        if ($get && $this->isNearMe()) {
            $position = $get($this->getName() . '__position');

            if (is_array($position) && count($position) === 2 && is_numeric($position[0]) && is_numeric($position[1])) {
                return [(float) $position[0], (float) $position[1]];
            }
        }

        return null;
    }

    /**
     * @return Builder<AddressModel>
     */
    public function getSearchQuery(string $search, ?Get $get = null, bool $contains = false): Builder
    {
        $query = AddressModel::query()->search($search, $contains);

        if (! $this->isNonResidential()) {
            $query->residential();
        }

        $point = $this->resolvePoint($get);

        if ($point !== null) {
            $query->near($point[0], $point[1], $this->evaluate($this->withinKm));
        } else {
            $query->ordered();
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    protected function searchAddresses(string $search, Get $get): array
    {
        $search = trim($search);

        // One character would order hundreds of thousands of rows for nothing.
        if (mb_strlen($search) < 2) {
            return [];
        }

        $limit = $this->getOptionsLimit();

        // Fast word-start match first; the contains-LIKE fallback only runs
        // when nothing starts with what was typed ("langen" → Im langen Loh).
        $addresses = $this->getSearchQuery($search, $get)->limit($limit)->get();

        if ($addresses->isEmpty()) {
            $addresses = $this->getSearchQuery($search, $get, contains: true)->limit($limit)->get();
        }

        $options = $addresses
            ->mapWithKeys(fn (AddressModel $address): array => [(string) $address->egaid => $address->line])
            ->all();

        if ($this->customColumn && mb_strlen($search) >= 3) {
            $options[self::CUSTOM_PREFIX . $search] = __('swissstreets-for-filament::swissstreets.field.custom', ['text' => $search]);
        }

        return $options;
    }

    protected function optionLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (self::isCustom($value)) {
            return $this->customColumn ? self::customText($value) : null;
        }

        return AddressModel::query()->find($value)?->line;
    }

    public static function isCustom(mixed $state): bool
    {
        return is_string($state) && str_starts_with($state, self::CUSTOM_PREFIX);
    }

    public static function customText(string $state): string
    {
        return trim(substr($state, strlen(self::CUSTOM_PREFIX)));
    }

    // ---- cascade mode ------------------------------------------------------

    /**
     * ZIP/town → street → house number. Only house numbers that exist for the
     * chosen street are offered. Stores the EGAID in `$name`.
     */
    public static function cascade(string $name, bool $nonresidential = false): Grid
    {
        $zipField = "{$name}__zip";
        $streetField = "{$name}__street";

        $base = fn (): Builder => $nonresidential
            ? AddressModel::query()
            : AddressModel::query()->residential();

        return Grid::make(['default' => 1, 'md' => 6])
            ->schema([
                Select::make($zipField)
                    ->label(fn (): string => __('swissstreets-for-filament::swissstreets.field.zip'))
                    ->searchable()
                    ->searchDebounce(250)
                    ->native(false)
                    ->dehydrated(false)
                    ->live()
                    ->columnSpan(['md' => 2])
                    ->getSearchResultsUsing(function (string $search) use ($base): array {
                        $search = trim($search);

                        if ($search === '') {
                            return [];
                        }

                        $key = AddressModel::searchKey($search);

                        return $base()
                            ->select(['zip', 'locality'])
                            ->distinct()
                            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('locality_search', '>=', $key)->where('locality_search', '<', $key . "\u{10FFFF}"))->orWhere('zip', 'like', "{$search}%"))
                            ->orderBy('zip')
                            ->orderBy('locality')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (AddressModel $a): array => ["{$a->zip} {$a->locality}" => "{$a->zip} {$a->locality}"])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $value : null)
                    ->afterStateUpdated(function (Set $set) use ($streetField, $name): void {
                        $set($streetField, null);
                        $set($name, null);
                    }),

                Select::make($streetField)
                    ->label(fn (): string => __('swissstreets-for-filament::swissstreets.field.street'))
                    ->searchable()
                    ->native(false)
                    ->dehydrated(false)
                    ->live()
                    ->columnSpan(['md' => 3])
                    ->disabled(fn (Get $get): bool => blank($get($zipField)))
                    ->options(function (Get $get) use ($base, $zipField): array {
                        [$zip, $locality] = self::splitZip($get($zipField));

                        if ($zip === null) {
                            return [];
                        }

                        return $base()
                            ->where('zip', $zip)
                            ->where('locality', $locality)
                            ->orderBy('street')
                            ->distinct()
                            ->pluck('street', 'street')
                            ->all();
                    })
                    ->afterStateUpdated(fn (Set $set) => $set($name, null)),

                Select::make($name)
                    ->label(fn (): string => __('swissstreets-for-filament::swissstreets.field.number'))
                    ->searchable()
                    ->native(false)
                    ->columnSpan(['md' => 1])
                    ->disabled(fn (Get $get): bool => blank($get($streetField)))
                    ->options(function (Get $get) use ($base, $zipField, $streetField): array {
                        [$zip, $locality] = self::splitZip($get($zipField));
                        $street = $get($streetField);

                        if ($zip === null || blank($street)) {
                            return [];
                        }

                        return $base()
                            ->where('zip', $zip)
                            ->where('locality', $locality)
                            ->where('street', $street)
                            ->ordered()
                            ->get()
                            ->mapWithKeys(fn (AddressModel $a): array => [(string) $a->egaid => $a->number ?? '–'])
                            ->all();
                    })
                    ->afterStateHydrated(function (mixed $state, Set $set) use ($zipField, $streetField): void {
                        if (blank($state)) {
                            return;
                        }

                        $address = AddressModel::query()->find($state);

                        if ($address) {
                            $set($zipField, "{$address->zip} {$address->locality}");
                            $set($streetField, $address->street);
                        }
                    }),
            ]);
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    protected static function splitZip(mixed $value): array
    {
        if (! is_string($value) || ! preg_match('/^(\d{4}) (.+)$/', $value, $m)) {
            return [null, null];
        }

        return [(int) $m[1], $m[2]];
    }
}

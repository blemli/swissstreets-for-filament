<?php

namespace Blemli\Swissstreets\Forms\Components;

use Blemli\Swissstreets\Models\Address as AddressModel;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * A very simple map picker: search the Swiss register to jump there, or drop
 * the pin anywhere (a field in the middle of nowhere). Writes latitude and
 * longitude columns; optionally the picked address id as well.
 *
 * State: ['lat' => float|null, 'lng' => float|null, 'search' => egaid|null]
 */
class MapPicker extends Field
{
    protected string $view = 'swissstreets-for-filament::forms.components.map-picker';

    protected string $latColumn = 'lat';

    protected string $lngColumn = 'lng';

    protected ?string $addressColumn = null;

    protected string | Closure $height = '20rem';

    protected int | Closure $zoom = 8;

    /** @var array{0: float, 1: float}|Closure */
    protected array | Closure $center = [46.8, 8.2];

    protected bool | Closure $isNonResidential = false;

    protected bool | Closure $hasSearch = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('swissstreets-for-filament::swissstreets.map_picker.label'));
        $this->default(['lat' => null, 'lng' => null, 'search' => null]);
        $this->dehydrated(false);

        $this->childComponents(fn (): array => $this->hasSearch() ? [
            Address::make('search')
                ->hiddenLabel()
                ->placeholder(fn (): string => __('swissstreets-for-filament::swissstreets.field.placeholder'))
                ->nonresidential(fn (): bool => $this->isNonResidential())
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    $address = filled($state) ? AddressModel::query()->find($state) : null;

                    if ($address) {
                        $set('lat', $address->lat);
                        $set('lng', $address->lng);
                    }
                }),
        ] : []);

        $this->afterStateHydrated(function (MapPicker $component, ?Model $record): void {
            if (! $record) {
                return;
            }

            $component->state([
                'lat' => $record->getAttribute($component->latColumn),
                'lng' => $record->getAttribute($component->lngColumn),
                'search' => $component->addressColumn ? $record->getAttribute($component->addressColumn) : null,
            ]);
        });

        $this->saveRelationshipsUsing(function (MapPicker $component, ?Model $record, mixed $state): void {
            if (! $record) {
                return;
            }

            $values = [
                $component->latColumn => self::coordinate($state['lat'] ?? null),
                $component->lngColumn => self::coordinate($state['lng'] ?? null),
            ];

            if ($component->addressColumn) {
                $values[$component->addressColumn] = filled($state['search'] ?? null) ? (int) $state['search'] : null;
            }

            $changed = collect($values)->contains(fn (mixed $value, string $column): bool => $record->getAttribute($column) != $value);

            if ($changed) {
                $record->forceFill($values)->saveQuietly();
            }
        });
    }

    // ---- configuration -----------------------------------------------------

    public function lat(string $column): static
    {
        $this->latColumn = $column;

        return $this;
    }

    public function lng(string $column): static
    {
        $this->lngColumn = $column;

        return $this;
    }

    /** Also store the address id (EGAID) when a register address was picked; cleared when the pin moves. */
    public function address(?string $column): static
    {
        $this->addressColumn = $column;

        return $this;
    }

    public function height(string | Closure $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function zoom(int | Closure $zoom): static
    {
        $this->zoom = $zoom;

        return $this;
    }

    /**
     * Initial map centre while nothing is picked (default: Switzerland).
     */
    public function center(float $lat, float $lng): static
    {
        $this->center = [$lat, $lng];

        return $this;
    }

    public function nonresidential(bool | Closure $condition = true): static
    {
        $this->isNonResidential = $condition;

        return $this;
    }

    /** Hide the register search, leaving pin placement only. */
    public function search(bool | Closure $condition = true): static
    {
        $this->hasSearch = $condition;

        return $this;
    }

    // ---- getters for the view --------------------------------------------

    public function getLatColumn(): string
    {
        return $this->latColumn;
    }

    public function getLngColumn(): string
    {
        return $this->lngColumn;
    }

    public function getAddressColumn(): ?string
    {
        return $this->addressColumn;
    }

    public function getHeight(): string
    {
        return (string) $this->evaluate($this->height);
    }

    public function getZoom(): int
    {
        return (int) $this->evaluate($this->zoom);
    }

    /**
     * @return array{0: float, 1: float}
     */
    public function getCenter(): array
    {
        return $this->evaluate($this->center);
    }

    public function isNonResidential(): bool
    {
        return (bool) $this->evaluate($this->isNonResidential);
    }

    public function hasSearch(): bool
    {
        return (bool) $this->evaluate($this->hasSearch);
    }

    /**
     * @return array<string, string>
     */
    public function getMapConfig(): array
    {
        return [
            'tiles' => (string) config('swissstreets-for-filament.map.tiles'),
            'attribution' => (string) config('swissstreets-for-filament.map.attribution'),
            'leafletJs' => (string) config('swissstreets-for-filament.map.leaflet_js'),
            'leafletCss' => (string) config('swissstreets-for-filament.map.leaflet_css'),
        ];
    }

    protected static function coordinate(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 7) : null;
    }
}

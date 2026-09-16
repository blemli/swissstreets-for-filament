<?php

namespace Blemli\Swissstreets\Forms\Components;

use Blemli\Swissstreets\Models\Address as AddressModel;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

/**
 * The address field as three selects — ZIP/town, street, house number — for
 * people who prefer that. Same features as the single field: vicinity via
 * ->near() / ->nearMe() (nearest towns first), ->nonresidential(), and
 * ->freetext() to add an unlisted or foreign address from any of the three.
 * Stores the EGAID in `$name`; the two helper selects are not saved.
 */
class AddressCascade extends Grid
{
    protected string $name = 'address_id';

    protected bool | Closure $isNonResidential = false;

    /** @var array{0: float, 1: float}|Closure|null */
    protected array | Closure | null $near = null;

    protected bool | Closure $isNearMe = false;

    protected bool | Closure $allowsFreetext = false;

    public static function for(string $name): static
    {
        $static = static::make(['default' => 1, 'md' => 6]);
        $static->name = $name;
        $static->schema(fn (): array => $static->cascadeComponents());
        $static->extraAttributes(fn (): array => $static->isNearMe() ? [
            'x-init' => "navigator.geolocation && navigator.geolocation.getCurrentPosition((p) => \$wire.\$set('{$static->getPositionStatePath()}', [p.coords.latitude, p.coords.longitude], false), () => {}, { maximumAge: 600000 })",
        ] : []);

        return $static;
    }

    // ---- configuration -----------------------------------------------------

    public function nonresidential(bool | Closure $condition = true): static
    {
        $this->isNonResidential = $condition;

        return $this;
    }

    /**
     * @param  array{0: float, 1: float}|Closure|float  $latOrPoint
     */
    public function near(array | Closure | float $latOrPoint, ?float $lng = null): static
    {
        $this->near = is_float($latOrPoint) ? [$latOrPoint, (float) $lng] : $latOrPoint;

        return $this;
    }

    public function nearMe(bool | Closure $condition = true): static
    {
        $this->isNearMe = $condition;

        return $this;
    }

    public function freetext(bool | Closure $condition = true): static
    {
        $this->allowsFreetext = $condition;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isNonResidential(): bool
    {
        return (bool) $this->evaluate($this->isNonResidential);
    }

    public function isNearMe(): bool
    {
        return (bool) $this->evaluate($this->isNearMe);
    }

    public function allowsFreetext(): bool
    {
        return (bool) $this->evaluate($this->allowsFreetext);
    }

    public function getPositionStatePath(): string
    {
        $container = $this->getContainer()->getStatePath();

        return ($container !== '' ? "{$container}." : '') . $this->name . '__position';
    }

    // ---- data --------------------------------------------------------------

    /**
     * @return Builder<AddressModel>
     */
    protected function base(): Builder
    {
        $query = AddressModel::query();

        if (! $this->isNonResidential()) {
            $query->residential();
        }

        return $query;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    public function resolvePoint(?Get $get = null): ?array
    {
        $point = $this->evaluate($this->near);

        if (is_array($point) && count($point) === 2 && is_numeric($point[0]) && is_numeric($point[1])) {
            return [(float) $point[0], (float) $point[1]];
        }

        if ($get && $this->isNearMe()) {
            $position = $get($this->name . '__position');

            if (is_array($position) && count($position) === 2 && is_numeric($position[0]) && is_numeric($position[1])) {
                return [(float) $position[0], (float) $position[1]];
            }
        }

        return null;
    }

    /**
     * Distinct towns, nearest first when a point is known, else by ZIP.
     *
     * @return array<string, string>
     */
    public function towns(string $search, ?Get $get = null, int $limit = 50): array
    {
        $query = $this->base()->select(['zip', 'locality'])->groupBy('zip', 'locality');
        $search = trim($search);

        if ($search !== '') {
            $key = AddressModel::searchKey($search);
            $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('locality_search', '>=', $key)->where('locality_search', '<', $key . "\u{10FFFF}"))
                ->orWhere('zip', 'like', "{$search}%"));
        }

        $point = $this->resolvePoint($get);

        if ($point !== null) {
            [$lat, $lng] = $point;
            $cos = cos(deg2rad($lat)) ** 2;
            $query->selectRaw('min(((lat - ?) * (lat - ?)) + ((lng - ?) * (lng - ?) * ?)) as distance', [$lat, $lat, $lng, $lng, $cos])
                ->whereNotNull('lat')
                ->orderBy('distance');
        } else {
            $query->orderBy('zip')->orderBy('locality');
        }

        return $query->limit($limit)->get()
            ->mapWithKeys(fn (AddressModel $a): array => ["{$a->zip} {$a->locality}" => "{$a->zip} {$a->locality}"])
            ->all();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    public static function splitTown(mixed $value): array
    {
        if (! is_string($value) || ! preg_match('/^(\S+) (.+)$/', $value, $m)) {
            return [null, null];
        }

        return [$m[1], $m[2]];
    }

    // ---- components --------------------------------------------------------

    /**
     * @return array<int, Select>
     */
    protected function cascadeComponents(): array
    {
        $name = $this->name;
        $zipField = "{$name}__zip";
        $streetField = "{$name}__street";
        $t = fn (string $key): string => __("swissstreets-for-filament::swissstreets.field.{$key}");

        $zip = Select::make($zipField)
            ->label($t('zip'))
            ->searchable()
            ->searchDebounce(250)
            ->native(false)
            ->dehydrated(false)
            ->live()
            ->columnSpan(['md' => 2])
            ->options(fn (Get $get): array => $this->resolvePoint($get) !== null ? $this->towns('', $get, 20) : [])
            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->towns($search, $get))
            ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $value : null)
            // Reset the dependents only when the chosen address no longer fits —
            // the ->freetext() form sets all three at once and must survive this.
            ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($streetField, $name): void {
                $address = filled($get($name)) ? AddressModel::query()->find($get($name)) : null;

                if ($address && "{$address->zip} {$address->locality}" === $state) {
                    return;
                }

                $set($streetField, null);
                $set($name, null);
            });

        $street = Select::make($streetField)
            ->label($t('street'))
            ->searchable()
            ->native(false)
            ->dehydrated(false)
            ->live()
            ->columnSpan(['md' => 3])
            ->disabled(fn (Get $get): bool => blank($get($zipField)))
            ->options(function (Get $get) use ($zipField): array {
                [$zip, $locality] = self::splitTown($get($zipField));

                if ($zip === null) {
                    return [];
                }

                return $this->base()
                    ->where('zip', $zip)
                    ->where('locality', $locality)
                    ->orderBy('street')
                    ->distinct()
                    ->pluck('street', 'street')
                    ->all();
            })
            ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($name): void {
                $address = filled($get($name)) ? AddressModel::query()->find($get($name)) : null;

                if ($address && $address->street === $state) {
                    return;
                }

                $set($name, null);
            });

        $number = Select::make($name)
            ->label($t('number'))
            ->searchable()
            ->native(false)
            ->columnSpan(['md' => 1])
            ->disabled(fn (Get $get): bool => blank($get($streetField)))
            ->options(function (Get $get) use ($zipField, $streetField): array {
                [$zip, $locality] = self::splitTown($get($zipField));
                $streetName = $get($streetField);

                if ($zip === null || blank($streetName)) {
                    return [];
                }

                return $this->base()
                    ->where('zip', $zip)
                    ->where('locality', $locality)
                    ->where('street', $streetName)
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
            });

        // ->freetext(): the same "add address" form on every step, prefilled
        // with what is already chosen; the new row is selected in all three.
        $this->attachFreetext($zip, fn (AddressModel $a): string => "{$a->zip} {$a->locality}", $zipField, $streetField, $name);
        $this->attachFreetext($street, fn (AddressModel $a): string => $a->street, $zipField, $streetField, $name);
        $this->attachFreetext($number, fn (AddressModel $a): int => $a->egaid, $zipField, $streetField, $name);

        return [$zip, $street, $number];
    }

    protected function attachFreetext(Select $select, Closure $ownValue, string $zipField, string $streetField, string $name): void
    {
        $select
            ->createOptionForm(fn (): array => $this->allowsFreetext() ? Address::freetextForm() : [])
            ->createOptionAction(fn (Action $action): Action => $action
                ->label(fn (): string => __('swissstreets-for-filament::swissstreets.freetext.action'))
                ->modalHeading(fn (): string => __('swissstreets-for-filament::swissstreets.freetext.heading'))
                ->modalDescription(fn (): string => __('swissstreets-for-filament::swissstreets.freetext.description'))
                ->modalWidth('lg')
                ->visible(fn (): bool => $this->allowsFreetext())
                ->fillForm(function (Get $get) use ($zipField, $streetField): array {
                    [$zip, $locality] = self::splitTown($get($zipField));

                    return array_filter([
                        'zip' => $zip,
                        'locality' => $locality,
                        'street' => $get($streetField),
                        'country' => $zip !== null ? 'CH' : (string) config('swissstreets-for-filament.default_foreign_country', 'DE'),
                    ], fn (mixed $v): bool => filled($v));
                }))
            ->createOptionUsing(function (array $data, Set $set) use ($ownValue, $zipField, $streetField, $name): int | string {
                $address = AddressModel::createManual($data);

                $set($zipField, "{$address->zip} {$address->locality}");
                $set($streetField, $address->street);
                $set($name, $address->egaid);

                return $ownValue($address);
            });
    }
}

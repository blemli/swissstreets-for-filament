<?php

namespace Blemli\Swissstreets\Forms\Components;

use Blemli\Swissstreets\Models\Address as AddressModel;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;

/**
 * Searchable select over the official Swiss address register. Stores the
 * address id (EGAID) in the given column.
 */
class Address extends Select
{
    protected bool | Closure $isNonResidential = false;

    /** @var array{0: float, 1: float}|Closure|null */
    protected array | Closure | null $near = null;

    protected bool | Closure $isNearMe = false;

    protected float | Closure | null $withinKm = null;

    protected bool | Closure $allowsFreetext = false;

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

        $this->createOptionForm(fn (): array => $this->allowsFreetext() ? self::freetextForm() : [])
            ->createOptionUsing(fn (array $data): int => AddressModel::createManual($data)->egaid)
            ->createOptionAction(fn (Action $action): Action => $action
                ->label(fn (): string => __('swissstreets-for-filament::swissstreets.freetext.action'))
                ->modalHeading(fn (): string => __('swissstreets-for-filament::swissstreets.freetext.heading'))
                ->modalDescription(fn (): string => __('swissstreets-for-filament::swissstreets.freetext.description'))
                ->modalWidth('lg')
                ->visible(fn (): bool => $this->allowsFreetext()));
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

    /**
     * Let users add an address the register does not know — foreign, or
     * simply missing. It becomes a real row (source "manual") so it shows up
     * in the addresses table like any other.
     */
    public function freetext(bool | Closure $condition = true): static
    {
        $this->allowsFreetext = $condition;

        return $this;
    }

    public function allowsFreetext(): bool
    {
        return (bool) $this->evaluate($this->allowsFreetext);
    }

    /**
     * The "add foreign address" form; reused by the cascade mode.
     *
     * @return array<int, Component>
     */
    public static function freetextForm(): array
    {
        $t = fn (string $key): string => __("swissstreets-for-filament::swissstreets.freetext.{$key}");

        return [
            TextInput::make('street')->label($t('street'))->required()->maxLength(120)->columnSpan(3),
            TextInput::make('number')->label($t('number'))->maxLength(16)->columnSpan(1),
            TextInput::make('zip')->label($t('zip'))->required()->maxLength(16)->columnSpan(1),
            TextInput::make('locality')->label($t('locality'))->required()->maxLength(120)->columnSpan(3),
            TextInput::make('country')
                ->label($t('country'))
                ->default(fn (): string => (string) config('swissstreets-for-filament.default_foreign_country', 'DE'))
                ->required()
                ->length(2)
                ->alpha()
                ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                ->columnSpan(1),
        ];
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
     * @return array<int|string, string>
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

        return $addresses
            ->mapWithKeys(fn (AddressModel $address): array => [(string) $address->egaid => $address->line])
            ->all();
    }

    protected function optionLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return AddressModel::query()->find($value)?->line;
    }

    // ---- cascade mode ------------------------------------------------------

    /**
     * The same field as three selects (ZIP/town → street → house number) for
     * people who prefer that; see AddressCascade for the options.
     */
    public static function cascade(string $name): AddressCascade
    {
        return AddressCascade::for($name);
    }
}

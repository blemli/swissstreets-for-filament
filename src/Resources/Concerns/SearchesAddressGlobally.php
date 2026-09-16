<?php

namespace Blemli\Swissstreets\Resources\Concerns;

use Blemli\Swissstreets\Models\Address;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Lets a resource list its address relation in getGloballySearchableAttributes():
 *
 *     use SearchesAddressGlobally;
 *     public static function getGloballySearchableAttributes(): array { return ['name', 'address']; }
 *
 * "spalen 113" then finds the customer living at Spalenring 113 — through
 * Address::scopeSearch() (accent-folded, ZIP as equality, house number), the
 * same matching as the address field and the addresses table, instead of
 * Filament's LIKE on a column that does not exist. Removed addresses never match.
 *
 * @mixin \Filament\Resources\Resource
 */
trait SearchesAddressGlobally
{
    /** @var array<string, bool> */
    protected static array $addressRelations = [];

    /**
     * @param  array<string>  $searchAttributes
     */
    protected static function applyGlobalSearchAttributeConstraint(Builder $query, string $search, array $searchAttributes, bool &$isFirst): Builder
    {
        foreach ($searchAttributes as $attribute) {
            if (! static::isAddressRelation($attribute)) {
                parent::applyGlobalSearchAttributeConstraint($query, $search, [$attribute], $isFirst);

                continue;
            }

            $whereHas = $isFirst ? 'whereHas' : 'orWhereHas';

            $query->{$whereHas}($attribute, fn (Builder $address) => $address->withoutTrashed()->search($search));

            $isFirst = false;
        }

        return $query;
    }

    /**
     * Default details line; a resource with its own getGlobalSearchResultDetails()
     * can merge this in.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::getGlobalSearchAddressDetails($record);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchAddressDetails(Model $record): array
    {
        $details = [];

        foreach (static::getGloballySearchableAttributes() as $attribute) {
            if (! is_string($attribute) || ! static::isAddressRelation($attribute)) {
                continue;
            }

            $address = $record->getRelationValue($attribute);

            if ($address instanceof Address && ! $address->trashed()) {
                $details[__('swissstreets-for-filament::swissstreets.address')] = $address->line;
            }
        }

        return $details;
    }

    /** True when the attribute is a relation of the resource's model pointing at Address. */
    protected static function isAddressRelation(string $attribute): bool
    {
        if (str_contains($attribute, '.')) {
            return false;
        }

        $model = static::getModel();

        return static::$addressRelations["{$model}.{$attribute}"] ??= (function () use ($model, $attribute): bool {
            if (! method_exists($model, $attribute)) {
                return false;
            }

            $relation = (new $model)->{$attribute}();

            return $relation instanceof Relation && $relation->getRelated() instanceof Address;
        })();
    }
}

<?php

namespace Blemli\Swissstreets\Concerns;

use Blemli\Swissstreets\Facades\Swissstreets;
use Blemli\Swissstreets\Models\Address;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ReflectionClass;

/**
 * Gives a model a `$model->address` relation and registers the model as an
 * address consumer so the "used" filter knows where to look.
 *
 * Override `addressColumn()` if the foreign key is not `address_id`.
 *
 * @mixin Model
 */
trait HasAddress
{
    public static function bootHasAddress(): void
    {
        // No `new static` here — the model is mid-boot and instantiating it throws.
        $defaults = (new ReflectionClass(static::class))->getDefaultProperties();

        Swissstreets::registerUsage(static::class, $defaults['addressColumn'] ?? 'address_id');
    }

    public function addressColumn(): string
    {
        return property_exists($this, 'addressColumn') ? $this->addressColumn : 'address_id';
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, $this->addressColumn(), 'egaid')->withTrashed();
    }
}

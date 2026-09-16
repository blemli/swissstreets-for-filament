<?php

namespace Blemli\Swissstreets\Events;

use Blemli\Swissstreets\Models\Address;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An address was restored — by the nightly import (source "register") or, for
 * AddressAdded, through the field's "+" form (source "manual").
 * Not dispatched per row on the very first full import (see log_initial_import).
 */
class AddressRestored
{
    use Dispatchable;

    public function __construct(public Address $address) {}
}

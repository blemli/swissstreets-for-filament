<?php

namespace Blemli\Swissstreets\Events;

use Blemli\Swissstreets\Import\ImportResult;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after every import run, including unchanged ones.
 */
class ImportFinished
{
    use Dispatchable;

    public function __construct(public ImportResult $result) {}
}

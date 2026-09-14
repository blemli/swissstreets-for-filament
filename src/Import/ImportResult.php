<?php

namespace Blemli\Swissstreets\Import;

use Carbon\CarbonInterval;

final class ImportResult
{
    public function __construct(
        public readonly bool $unchanged,
        public readonly int $added = 0,
        public readonly int $removed = 0,
        public readonly int $restored = 0,
        public readonly int $total = 0,
        public readonly int $skipped = 0,
        public readonly float $seconds = 0.0,
        public readonly bool $initial = false,
    ) {}

    public static function unchanged(): self
    {
        return new self(unchanged: true);
    }

    public function duration(): string
    {
        return CarbonInterval::seconds((int) round($this->seconds))->cascade()->forHumans(short: true);
    }
}

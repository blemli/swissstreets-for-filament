<?php

namespace Blemli\Swissstreets\Import;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Outcome of the last runs, kept in the cache: what the health check reads for
 * "yellow after N failed imports" and what the panel shows as the last result.
 * A run refused because of the lock is not a run and is not recorded.
 */
class ImportStatus
{
    public const KEY = 'swissstreets:import:status';

    public function recordSuccess(ImportResult $result): void
    {
        Cache::forever(self::KEY, [
            'last_success_at' => now()->toIso8601String(),
            'last_result' => $result->unchanged ? 'unchanged' : 'imported',
            'failures' => 0,
            'last_error' => null,
            'last_failure_at' => $this->get()['last_failure_at'] ?? null,
        ]);
    }

    public function recordFailure(Throwable $e): void
    {
        $status = $this->get();

        Cache::forever(self::KEY, [
            'last_success_at' => $status['last_success_at'] ?? null,
            'last_result' => 'failed',
            'failures' => (int) ($status['failures'] ?? 0) + 1,
            'last_error' => $e->getMessage(),
            'last_failure_at' => now()->toIso8601String(),
        ]);
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /** Failed runs in a row since the last successful one. */
    public function consecutiveFailures(): int
    {
        return (int) ($this->get()['failures'] ?? 0);
    }

    public function lastError(): ?string
    {
        return $this->get()['last_error'] ?? null;
    }

    public function lastSuccessAt(): ?Carbon
    {
        $at = $this->get()['last_success_at'] ?? null;

        return $at ? Carbon::parse($at) : null;
    }

    public function lastFailureAt(): ?Carbon
    {
        $at = $this->get()['last_failure_at'] ?? null;

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $status = Cache::get(self::KEY);

        return is_array($status) ? $status : [];
    }
}

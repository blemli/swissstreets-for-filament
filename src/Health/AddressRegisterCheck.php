<?php

namespace Blemli\Swissstreets\Health;

use Blemli\Swissstreets\Import\ImportStatus;
use Blemli\Swissstreets\Models\Address;
use Illuminate\Support\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * spatie/laravel-health check: red when no address was created, updated or
 * removed for `max_age_days` (swisstopo publishes daily, so a quiet register
 * means the scheduler or the import is broken), yellow when the last
 * `max_failed_runs` imports failed in a row.
 *
 * Registered by the plugin when `health.enabled` is set — never register it a
 * second time by hand, spatie refuses duplicate check names.
 */
class AddressRegisterCheck extends Check
{
    protected ?int $maxAgeDays = null;

    protected ?int $maxFailedRuns = null;

    public function maxAgeInDays(int $days): static
    {
        $this->maxAgeDays = $days;

        return $this;
    }

    public function maxFailedRuns(int $runs): static
    {
        $this->maxFailedRuns = $runs;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?: __('swissstreets-for-filament::swissstreets.health.label');
    }

    public function run(): Result
    {
        $maxAge = $this->maxAgeDays ?? (int) config('swissstreets-for-filament.health.max_age_days', 21);
        $maxFailed = $this->maxFailedRuns ?? (int) config('swissstreets-for-filament.health.max_failed_runs', 3);

        $status = new ImportStatus;
        $latest = $this->latestChange();
        $failures = $status->consecutiveFailures();

        $result = Result::make()->meta([
            'latest_change' => $latest?->toIso8601String(),
            'max_age_days' => $maxAge,
            'failed_runs' => $failures,
            'last_error' => $status->lastError(),
        ]);

        if ($latest === null) {
            return $result
                ->shortSummary(__('swissstreets-for-filament::swissstreets.health.summary_empty'))
                ->failed(__('swissstreets-for-filament::swissstreets.health.empty'));
        }

        $days = (int) $latest->diffInDays(now());

        if ($days > $maxAge) {
            return $result
                ->shortSummary(__('swissstreets-for-filament::swissstreets.health.summary_days', ['days' => $days]))
                ->failed(__('swissstreets-for-filament::swissstreets.health.stale', ['days' => $days, 'max' => $maxAge]));
        }

        if ($failures >= $maxFailed) {
            return $result
                ->shortSummary(__('swissstreets-for-filament::swissstreets.health.summary_failed', ['count' => $failures]))
                ->warning(__('swissstreets-for-filament::swissstreets.health.failing', ['count' => $failures, 'error' => (string) $status->lastError()]));
        }

        return $result
            ->shortSummary(__('swissstreets-for-filament::swissstreets.health.summary_days', ['days' => $days]))
            ->ok(__('swissstreets-for-filament::swissstreets.health.ok', ['ago' => $latest->diffForHumans()]));
    }

    /** Newest created_at / updated_at / deleted_at over the whole table, removed rows included. */
    protected function latestChange(): ?Carbon
    {
        $stamps = Address::withTrashed()
            ->toBase()
            ->selectRaw('max(created_at) as created, max(updated_at) as updated, max(deleted_at) as deleted')
            ->first();

        $latest = collect([$stamps?->created, $stamps?->updated, $stamps?->deleted])
            ->filter()
            ->map(fn ($stamp): Carbon => Carbon::parse($stamp))
            ->max();

        return $latest instanceof Carbon ? $latest : null;
    }
}

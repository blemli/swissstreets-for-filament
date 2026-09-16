<?php

namespace Blemli\Swissstreets\Import;

use Blemli\Swissstreets\Facades\Swissstreets;
use Blemli\Swissstreets\Models\Address;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class Importer
{
    protected ?Closure $progress = null;

    public function __construct(
        protected Downloader $downloader,
        protected CsvReader $reader,
    ) {}

    /** Called with the number of rows processed so far. */
    public function onProgress(?Closure $callback): static
    {
        $this->progress = $callback;

        return $this;
    }

    /**
     * @param  string|null  $file  Local .zip or .csv instead of downloading (fully offline).
     * @param  bool  $force  Import even when the remote file is unchanged.
     */
    public function run(?string $file = null, bool $force = false): ImportResult
    {
        $lock = Cache::lock('swissstreets:import', 7200);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Another swissstreets import is still running.');
        }

        try {
            $result = $this->import($file, $force);
        } catch (Throwable $e) {
            $this->notifyFailure($e);

            throw $e;
        } finally {
            $lock->release();
        }

        $this->notify($result);

        return $result;
    }

    protected function import(?string $file, bool $force): ImportResult
    {
        $started = microtime(true);
        $version = null;

        if ($file === null) {
            $version = $this->downloader->remoteVersion();
            $hasRows = Address::withTrashed()->exists();

            if (! $force && $hasRows && $version !== null && $version === Cache::get(Downloader::VERSION_CACHE_KEY)) {
                return ImportResult::unchanged();
            }

            $file = $this->downloader->download();
        }

        $initial = ! Address::withTrashed()->exists();
        // Microsecond precision so two runs within the same second still
        // tell "seen in this run" from "seen in the previous run".
        $now = now()->format('Y-m-d H:i:s.u');
        $stamp = now()->toDateTimeString();
        $mapper = new RowMapper;
        $chunkSize = max(50, (int) config('swissstreets-for-filament.chunk_size', 500));
        $logRows = ! $initial || (bool) config('swissstreets-for-filament.log_initial_import', false);

        $added = $restored = $total = $skipped = $processed = 0;
        $buffer = [];

        foreach ($this->reader->rows($file) as $row) {
            $processed++;
            $mapped = $mapper->map($row, $now, $stamp);

            if ($mapped === null) {
                $skipped++;
            } else {
                $total++;
                $buffer[$mapped['egaid']] = $mapped;

                if (count($buffer) >= $chunkSize) {
                    [$new, $revived] = $this->upsert($buffer, $logRows);
                    $added += $new;
                    $restored += $revived;
                    $buffer = [];
                }
            }

            if ($this->progress && $processed % 10000 === 0) {
                ($this->progress)($processed);
            }
        }

        if ($buffer !== []) {
            [$new, $revived] = $this->upsert($buffer, $logRows);
            $added += $new;
            $restored += $revived;
        }

        if ($total === 0) {
            throw new RuntimeException('The source file yielded no addresses — refusing to remove everything.');
        }

        $removed = $this->removeMissing($now, $stamp, $logRows);

        $this->refreshStatistics();

        if ($version !== null) {
            Cache::forever(Downloader::VERSION_CACHE_KEY, $version);
        }

        return new ImportResult(
            unchanged: false,
            added: $added,
            removed: $removed,
            restored: $restored,
            total: $total,
            skipped: $skipped,
            seconds: microtime(true) - $started,
            initial: $initial,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: int, 1: int} [added, restored]
     */
    protected function upsert(array $rows, bool $logRows): array
    {
        $ids = array_keys($rows);

        $existing = Address::withTrashed()
            ->whereIn('egaid', $ids)
            ->get(['egaid', 'deleted_at'])
            ->keyBy('egaid');

        $newIds = array_values(array_diff($ids, $existing->keys()->all()));
        $restoredIds = $existing->filter(fn (Address $a): bool => $a->deleted_at !== null)->keys()->all();

        DB::transaction(function () use ($rows): void {
            Address::withTrashed()->upsert(
                array_values($rows),
                ['egaid'],
                ['egid', 'street', 'number', 'number_int', 'zip', 'locality', 'commune', 'street_search', 'locality_search', 'commune_search', 'canton', 'category', 'lat', 'lng', 'easting', 'northing', 'modified_at', 'imported_at', 'updated_at', 'deleted_at'],
            );
        });

        if ($logRows) {
            $this->record($newIds, 'added');
            $this->record($restoredIds, 'restored');
        }

        return [count($newIds), count($restoredIds)];
    }

    protected function removeMissing(string $now, string $stamp, bool $logRows): int
    {
        $query = Address::query()->where(fn ($q) => $q->where('imported_at', '<', $now)->orWhereNull('imported_at'));
        $count = 0;

        $query->clone()->orderBy('egaid')->chunkById(500, function ($addresses) use (&$count, $logRows, $stamp): void {
            /** @var \Illuminate\Database\Eloquent\Collection<int, Address> $addresses */
            $count += $addresses->count();

            if ($logRows) {
                $this->log($addresses, 'removed');
            }

            Address::query()->whereIn('egaid', $addresses->modelKeys())->update(['deleted_at' => $stamp]);
        }, 'egaid');

        return $count;
    }

    /**
     * Fresh planner statistics after two million upserts, so SQLite picks the
     * street/locality indexes for searches.
     */
    protected function refreshStatistics(): void
    {
        $connection = (new Address)->getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $connection->statement('ANALYZE ' . (new Address)->getTable());
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    protected function record(array $ids, string $event): void
    {
        if ($ids === []) {
            return;
        }

        $this->log(Address::withTrashed()->whereIn('egaid', $ids)->get(), $event);
    }

    /**
     * @param  Collection<int, Address>  $addresses
     */
    protected function log($addresses, string $event): void
    {
        $logger = Log::channel(config('swissstreets-for-filament.log_channel'));
        $activity = Swissstreets::activitylogAvailable();
        $description = __("swissstreets-for-filament::swissstreets.activity.{$event}");

        foreach ($addresses as $address) {
            $logger->info(sprintf('swissstreets: %s address %d — %s', $event, $address->egaid, $address->line));

            if ($activity) {
                activity('swissstreets')
                    ->performedOn($address)
                    ->event($event)
                    ->withProperties(['egaid' => $address->egaid, 'address' => $address->line])
                    ->log($description);
            }
        }
    }

    protected function notify(ImportResult $result): void
    {
        // An unchanged register is the normal night — no notification for that.
        if ($result->unchanged) {
            return;
        }

        $recipients = Swissstreets::notificationRecipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('swissstreets-for-filament::swissstreets.notification.title'))
            ->icon('heroicon-o-map-pin')
            ->body(__('swissstreets-for-filament::swissstreets.notification.body', [
                'added' => number_format($result->added),
                'removed' => number_format($result->removed),
                'restored' => number_format($result->restored),
                'duration' => $result->duration(),
            ]))
            ->success()
            ->sendToDatabase($recipients);
    }

    protected function notifyFailure(Throwable $e): void
    {
        $recipients = Swissstreets::notificationRecipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('swissstreets-for-filament::swissstreets.notification.failed'))
            ->body($e->getMessage())
            ->danger()
            ->icon('heroicon-o-map-pin')
            ->sendToDatabase($recipients);
    }
}

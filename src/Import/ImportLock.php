<?php

namespace Blemli\Swissstreets\Import;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one place that knows the import lock: the command, the importer, the
 * panel button and the health check all go through here, so key, TTL and the
 * "who holds it" metadata can never drift apart.
 *
 * A killed process (Ctrl-C, kill, OOM) never reaches the release, so next to
 * the lock a metadata entry records pid, host and start time. That makes the
 * refusal message actionable and lets a lock whose process died on this host
 * heal itself.
 */
class ImportLock
{
    public const KEY = 'swissstreets:import';

    public const META_KEY = 'swissstreets:import:running';

    /**
     * Seconds. A whole-country import on a slow box must fit comfortably; every
     * regular exit path releases the lock explicitly, the TTL is only the safety net.
     */
    public const TTL = 4 * 3600;

    protected ?Lock $lock = null;

    /**
     * @param  string  $trigger  cli|schedule|panel — shown in the refusal message
     */
    public function acquire(string $trigger = 'cli'): bool
    {
        $lock = Cache::lock(self::KEY, self::TTL);
        $acquired = $lock->get();

        if (! $acquired && $this->isStale()) {
            $this->forceRelease();
            $acquired = $lock->get();
        }

        if (! $acquired) {
            return false;
        }

        $this->lock = $lock;

        Cache::put(self::META_KEY, [
            'pid' => getmypid(),
            'host' => gethostname(),
            'started_at' => now()->toIso8601String(),
            'trigger' => $trigger,
        ], self::TTL);

        return true;
    }

    /** Release our own lock (a lock held by someone else stays untouched). */
    public function release(): void
    {
        $this->lock?->release();
        $this->lock = null;

        Cache::forget(self::META_KEY);
    }

    /** Release whoever holds the lock, plus the scheduler's overlap mutex. */
    public function forceRelease(): void
    {
        Cache::lock(self::KEY)->forceRelease();
        Cache::forget(self::META_KEY);
        $this->lock = null;

        $this->forgetScheduleMutex();
    }

    public function isLocked(): bool
    {
        return ! Cache::lock(self::KEY)->get(fn (): bool => true);
    }

    /**
     * @return array{pid: int, host: string, started_at: string, trigger: string}|null
     */
    public function metadata(): ?array
    {
        $meta = Cache::get(self::META_KEY);

        return is_array($meta) ? $meta : null;
    }

    /**
     * True when the recorded process ran on this host and is gone. Cannot be
     * decided for another host (shared Redis/database cache) — then --unlock it is.
     */
    public function isStale(): bool
    {
        $meta = $this->metadata();

        if ($meta === null || ! function_exists('posix_kill') || $meta['host'] !== gethostname()) {
            return false;
        }

        $pid = (int) $meta['pid'];

        return $pid > 0 && ! posix_kill($pid, 0);
    }

    /** Human-readable refusal, English (console output stays untranslated). */
    public function describe(): string
    {
        $hint = 'php artisan swissstreets:import --unlock (also clears the scheduler\'s overlap mutex, see php artisan schedule:clear-cache)';
        $meta = $this->metadata();

        if ($meta === null) {
            return "Another swissstreets import is still running (no details recorded). If no import process is alive: {$hint}";
        }

        $since = Carbon::parse($meta['started_at'])->format('Y-m-d H:i');
        $trigger = match ($meta['trigger']) {
            'schedule' => 'the scheduler',
            'panel' => 'the panel',
            default => 'the command line',
        };

        return "Another swissstreets import has been running since {$since} (PID {$meta['pid']} on {$meta['host']}, started from {$trigger}). If that process is gone: {$hint}";
    }

    /**
     * withoutOverlapping() in routes/console.php is a second, independent mutex
     * with the same stuck-after-kill behaviour; forget only ours.
     */
    protected function forgetScheduleMutex(): void
    {
        try {
            foreach (app(Schedule::class)->events() as $event) {
                if (str_contains((string) $event->command, self::KEY)) {
                    $event->mutex->forget($event);
                }
            }
        } catch (Throwable) {
            // No schedule loaded (plain unit test, missing cache store): nothing to forget.
        }
    }
}

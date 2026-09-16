<?php

namespace Blemli\Swissstreets\Support;

use Illuminate\Support\Facades\File;

/**
 * Writes (and removes) the nightly import entry in the app's routes/console.php.
 * The plugin never registers the schedule silently — the install command asks.
 */
class ScheduleInstaller
{
    public const MARKER = '// swissstreets: nightly address import (added by swissstreets-for-filament:install)';

    public function path(): string
    {
        return base_path('routes/console.php');
    }

    public function isInstalled(): bool
    {
        return File::exists($this->path()) && str_contains(File::get($this->path()), self::MARKER);
    }

    public static function isValidTime(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    public function snippet(string $time): string
    {
        return implode("\n", [
            self::MARKER,
            "\\Illuminate\\Support\\Facades\\Schedule::command('swissstreets:import')",
            "    ->dailyAt('{$time}')",
            '    ->withoutOverlapping(120)',
            '    ->runInBackground();',
        ]);
    }

    /**
     * @return 'added'|'created'|'exists'
     */
    public function install(string $time): string
    {
        if ($this->isInstalled()) {
            return 'exists';
        }

        $path = $this->path();

        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, "<?php\n\n" . $this->snippet($time) . "\n");

            return 'created';
        }

        File::append($path, "\n" . $this->snippet($time) . "\n");

        return 'added';
    }

    public function remove(): bool
    {
        if (! $this->isInstalled()) {
            return false;
        }

        $path = $this->path();
        $pattern = '/\n?' . preg_quote(self::MARKER, '/') . '\n.*?->runInBackground\(\);\n/s';
        $contents = preg_replace($pattern, '', File::get($path));

        File::put($path, (string) $contents);

        return true;
    }
}

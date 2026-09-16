<?php

namespace Blemli\Swissstreets\Import;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Every way an import can fail, worded for the person who has to act on it:
 * the console prints it, the panel notification carries it, the installer
 * repeats it. English only — console output stays untranslated.
 */
class ImportFailed extends RuntimeException
{
    public const RETRY_HINT = 'php artisan swissstreets:import';

    public static function unreachable(ConnectionException $e, string $url): self
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return new self(
            "Could not reach swisstopo ({$host}): " . self::reason($e->getMessage()) . '. Check the network and run again: ' . self::RETRY_HINT,
            previous: $e,
        );
    }

    public static function rejected(RequestException $e): self
    {
        return new self(
            "swisstopo answered HTTP {$e->response->status()} for the address register. Try again later: " . self::RETRY_HINT,
            previous: $e,
        );
    }

    public static function locked(ImportLock $lock): self
    {
        return new self($lock->describe());
    }

    /**
     * "cURL error 35: Recv failure: Connection reset by peer (see https://curl.se/…) for https://data.geo.admin.ch/…"
     * → "Recv failure: Connection reset by peer"
     */
    protected static function reason(string $message): string
    {
        $reason = preg_replace('/^cURL error \d+: /', '', $message) ?? $message;
        $reason = preg_replace('/ \(see https?:\/\/\S+\)/', '', $reason) ?? $reason;
        $reason = preg_replace('/ for https?:\/\/\S+$/', '', $reason) ?? $reason;

        return rtrim(trim($reason), '.');
    }
}

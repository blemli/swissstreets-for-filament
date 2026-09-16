<?php

namespace Blemli\Swissstreets\Import;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Downloader
{
    public const VERSION_CACHE_KEY = 'swissstreets.source_version';

    /**
     * Milliseconds between attempts. data.geo.admin.ch sits behind CloudFront,
     * which now and then resets a fresh TLS connection; a few seconds later it
     * is fine. Only transport errors are retried, never a 4xx/5xx answer.
     */
    public const BACKOFF_MS = [2000, 5000, 10000];

    public function url(): string
    {
        return (string) config('swissstreets-for-filament.source_url');
    }

    public function zipPath(): string
    {
        return storage_path(trim((string) config('swissstreets-for-filament.storage_path', 'app/swissstreets'), '/') . '/register.csv.zip');
    }

    /**
     * Version stamp of the remote file (Last-Modified, else ETag), null if unknown.
     *
     * The probe is an optimisation only: when swisstopo cannot be reached the
     * import goes on to the download, which has its own retries.
     */
    public function remoteVersion(): ?string
    {
        try {
            $response = $this->request()
                ->timeout(30)
                ->retry(self::BACKOFF_MS, when: fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
                ->head($this->url());
        } catch (ConnectionException $e) {
            Log::warning('swissstreets: could not probe swisstopo for the register version, downloading anyway', ['reason' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $response->header('Last-Modified') ?: ($response->header('ETag') ?: null);
    }

    public function download(): string
    {
        $path = $this->zipPath();
        File::ensureDirectoryExists(dirname($path));

        $tmp = $path . '.part';

        try {
            retry(self::BACKOFF_MS, function () use ($tmp): void {
                // Every attempt starts from zero: a half-written sink must never be moved into place.
                File::delete($tmp);

                $this->request()
                    ->timeout(1800)
                    ->withOptions(['sink' => $tmp])
                    ->get($this->url())
                    ->throw();
            }, when: fn (Throwable $e): bool => $e instanceof ConnectionException);
        } catch (ConnectionException $e) {
            File::delete($tmp);

            throw ImportFailed::unreachable($e, $this->url());
        } catch (RequestException $e) {
            File::delete($tmp);

            throw ImportFailed::rejected($e);
        }

        File::move($tmp, $path);

        return $path;
    }

    /** Remove a half-written download (aborted run). */
    public function cleanup(): void
    {
        File::delete($this->zipPath() . '.part');
    }

    protected function request(): PendingRequest
    {
        return Http::connectTimeout(15);
    }
}

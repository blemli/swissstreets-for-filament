<?php

namespace Blemli\Swissstreets\Import;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class Downloader
{
    public const VERSION_CACHE_KEY = 'swissstreets.source_version';

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
     */
    public function remoteVersion(): ?string
    {
        $response = Http::timeout(30)->head($this->url());

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

        Http::timeout(1800)
            ->withOptions(['sink' => $tmp])
            ->get($this->url())
            ->throw();

        File::move($tmp, $path);

        return $path;
    }
}

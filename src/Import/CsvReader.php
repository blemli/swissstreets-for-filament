<?php

namespace Blemli\Swissstreets\Import;

use Generator;
use RuntimeException;
use ZipArchive;

/**
 * Streams rows out of the swisstopo CSV — from a .csv or straight out of the
 * .zip, without unpacking the ~470 MB file to disk.
 */
class CsvReader
{
    /**
     * @return Generator<int, array<string, string>>
     */
    public function rows(string $path): Generator
    {
        $zip = null;

        if (str_ends_with(strtolower($path), '.zip')) {
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                throw new RuntimeException("Cannot open zip archive [{$path}].");
            }

            $entry = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (str_ends_with(strtolower($name), '.csv')) {
                    $entry = $name;

                    break;
                }
            }

            if ($entry === null) {
                throw new RuntimeException("No CSV file inside [{$path}].");
            }

            $handle = $zip->getStream($entry);
        } else {
            $handle = fopen($path, 'r');
        }

        if ($handle === false) {
            throw new RuntimeException("Cannot read [{$path}].");
        }

        try {
            $header = fgetcsv($handle, 0, ';', '"', '');

            if (! is_array($header)) {
                throw new RuntimeException("Empty CSV [{$path}].");
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $count = count($header);

            while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
                if ($row === [null] || count($row) !== $count) {
                    continue;
                }

                /** @var array<string, string> $assoc */
                $assoc = array_combine($header, $row);

                yield $assoc;
            }
        } finally {
            fclose($handle);
            $zip?->close();
        }
    }
}

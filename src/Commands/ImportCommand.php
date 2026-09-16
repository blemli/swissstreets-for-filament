<?php

namespace Blemli\Swissstreets\Commands;

use Blemli\Swissstreets\Import\Importer;
use Illuminate\Console\Command;
use Throwable;

class ImportCommand extends Command
{
    public $signature = 'swissstreets:import
        {--file= : Import a local .zip or .csv instead of downloading}
        {--force : Import even if the remote file has not changed}
        {--unlock : Release a lock left behind by a killed run, then import}';

    public $description = 'Import the official Swiss building address register (swisstopo)';

    public function handle(Importer $importer): int
    {
        $file = $this->option('file');

        if ($file !== null && ! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->info($file ? "Importing from {$file}" : 'Checking swisstopo for a new address register…');

        $importer->onProgress(function (int $rows): void {
            $this->output->write("\r  " . number_format($rows) . ' rows read');
        });

        // Ctrl-C / kill: free the lock and the half download instead of leaving
        // "still running" behind for the next two hours. No-op without pcntl.
        $this->trap(fn (): array => [SIGINT, SIGTERM], function (int $signal) use ($importer): void {
            $importer->abort();
            $this->newLine();
            $this->error('Import aborted.');

            exit(128 + $signal);
        });

        try {
            $result = $importer->run($file, (bool) $this->option('force'), (bool) $this->option('unlock'));
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        if ($result->unchanged) {
            $this->info('Source unchanged since the last import, nothing to do (use --force to import anyway).');

            return self::SUCCESS;
        }

        $this->table(['Imported', 'Added', 'Removed', 'Restored', 'Skipped', 'Duration'], [[
            number_format($result->total),
            number_format($result->added),
            number_format($result->removed),
            number_format($result->restored),
            number_format($result->skipped),
            $result->duration(),
        ]]);

        if ($result->initial) {
            $this->line('Initial import: per-address log entries were skipped.');
        }

        return self::SUCCESS;
    }
}

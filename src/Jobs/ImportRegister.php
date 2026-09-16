<?php

namespace Blemli\Swissstreets\Jobs;

use Blemli\Swissstreets\Facades\Swissstreets;
use Blemli\Swissstreets\Import\Importer;
use Blemli\Swissstreets\Import\ImportResult;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The panel's "Import" button: the same Importer run as the CLI and the
 * scheduler, on the queue (inline on the sync driver). Whoever pressed the
 * button hears back — also when the register turned out to be unchanged,
 * which the configured recipients deliberately never hear about.
 */
class ImportRegister implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A whole-country download plus upsert must fit. */
    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public ?Model $user = null,
        public bool $force = false,
        public ?string $file = null,
    ) {}

    public function handle(Importer $importer): void
    {
        try {
            $result = $importer->run($this->file, $this->force, trigger: 'panel');
        } catch (Throwable $e) {
            $this->tell(
                Notification::make()
                    ->title(__('swissstreets-for-filament::swissstreets.notification.failed'))
                    ->body($e->getMessage())
                    ->danger(),
                unlessRecipient: true,
            );

            throw $e;
        }

        $this->tell(
            Notification::make()
                ->title(__('swissstreets-for-filament::swissstreets.notification.title'))
                ->body($this->body($result))
                ->success(),
            unlessRecipient: ! $result->unchanged,
        );
    }

    protected function body(ImportResult $result): string
    {
        if ($result->unchanged) {
            return __('swissstreets-for-filament::swissstreets.import.unchanged');
        }

        return __('swissstreets-for-filament::swissstreets.notification.body', [
            'added' => number_format($result->added),
            'removed' => number_format($result->removed),
            'restored' => number_format($result->restored),
            'duration' => $result->duration(),
        ]);
    }

    /**
     * @param  bool  $unlessRecipient  Skip when the Importer already notified this user as a configured recipient.
     */
    protected function tell(Notification $notification, bool $unlessRecipient): void
    {
        if ($this->user === null) {
            return;
        }

        if ($unlessRecipient && Swissstreets::notificationRecipients()->contains(fn (Model $recipient): bool => $recipient->is($this->user))) {
            return;
        }

        $notification->icon('heroicon-o-map-pin')->sendToDatabase($this->user);
    }
}

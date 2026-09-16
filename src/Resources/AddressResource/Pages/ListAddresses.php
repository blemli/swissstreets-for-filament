<?php

namespace Blemli\Swissstreets\Resources\AddressResource\Pages;

use Blemli\Swissstreets\Import\ImportLock;
use Blemli\Swissstreets\Jobs\ImportRegister;
use Blemli\Swissstreets\Resources\AddressResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ListAddresses extends ListRecords
{
    protected static string $resource = AddressResource::class;

    protected function getHeaderActions(): array
    {
        $t = fn (string $key, array $replace = []): string => __("swissstreets-for-filament::swissstreets.import.{$key}", $replace);
        $lock = app(ImportLock::class);

        return [
            // Fetch from swisstopo, on the queue (never inline: a whole-country
            // import outlives any web request). Disabled while a run is going.
            Action::make('import')
                ->label(fn (): string => $lock->isLocked() ? $t('running') : $t('action'))
                ->icon(Heroicon::OutlinedCloudArrowDown)
                ->visible(fn (): bool => AddressResource::canImport())
                ->disabled(fn (): bool => $lock->isLocked())
                ->tooltip(function () use ($lock, $t): ?string {
                    $meta = $lock->isLocked() ? $lock->metadata() : null;

                    return $meta ? $t('running_since', ['since' => Carbon::parse($meta['started_at'])->translatedFormat('H:i')]) : null;
                })
                ->requiresConfirmation()
                ->modalHeading($t('heading'))
                ->modalDescription($t('description'))
                ->modalSubmitActionLabel($t('action'))
                ->action(function () use ($lock, $t): void {
                    if ($lock->isLocked()) {
                        Notification::make()->title($t('locked'))->warning()->send();

                        return;
                    }

                    ImportRegister::dispatch(Filament::auth()->user());

                    Notification::make()->title($t('queued'))->success()->send();
                }),
        ];
    }
}

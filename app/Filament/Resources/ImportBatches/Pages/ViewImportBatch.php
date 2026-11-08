<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Actions\Import\RetryFailedImportRows;
use App\Exceptions\ImportRetryRefusedException;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\ImportBatch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected string $view = 'filament.resources.import-batches.pages.view-import-batch';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryFailedRows')
                ->label('Retry Failed Rows')
                ->color('warning')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->visible(fn (ImportBatch $record) => $record->hasFinished() && $record->failedRows()->exists())
                ->action(function (ImportBatch $record, RetryFailedImportRows $retry) {
                    try {
                        $count = $retry->handle($record);
                    } catch (ImportRetryRefusedException $e) {
                        Notification::make()
                            ->title($e->getMessage())
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Retrying {$count} failed rows.")
                        ->success()
                        ->send();
                }),
        ];
    }
}

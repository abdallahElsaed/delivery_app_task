<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Enums\Import\ImportBatchRowStatus;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Jobs\Import\ProcessProductImportChunk;
use App\Models\ImportBatch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    public function getPollingInterval(): ?string
    {
        $status = $this->getRecord()->status;

        return in_array($status, ['pending', 'processing']) ? '3s' : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryFailedRows')
                ->label('Retry Failed Rows')
                ->color('warning')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->visible(fn (ImportBatch $record) => $record->failed_rows > 0)
                ->action(function (ImportBatch $record) {
                    $failedRows = $record->failedRows()->get();

                    if ($failedRows->isEmpty()) {
                        Notification::make()
                            ->title('No failed rows to retry.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $record->failedRows()->update(['status' => ImportBatchRowStatus::RETRYING]);
                    $rows = $failedRows->pluck('raw_data')->toArray();
                    $record->update(['failed_rows' => 0]);

                    ProcessProductImportChunk::dispatch($record->id, $rows)
                        ->onQueue('imports');

                    Notification::make()
                        ->title("Retrying {$failedRows->count()} failed rows.")
                        ->success()
                        ->send();
                }),
        ];
    }
}

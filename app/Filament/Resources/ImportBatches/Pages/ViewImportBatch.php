<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    public function getPollingInterval(): ?string
    {
        $status = $this->getRecord()->status;

        return in_array($status, ['pending', 'processing']) ? '3s' : null;
    }
}

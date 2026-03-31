<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Service\Imports\ProductImportProcessor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateImportBatch extends CreateRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $filePath = storage_path('app/' . $data['file']);

        /** @var ProductImportProcessor $processor */
        $processor = app(ProductImportProcessor::class);

        return $processor->handle($filePath);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

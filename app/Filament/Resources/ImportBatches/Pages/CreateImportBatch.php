<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Service\Imports\ProductImportProcessor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CreateImportBatch extends CreateRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $filePath = Storage::disk('local')->path($data['file']);
        /** @var ProductImportProcessor $processor */
        $processor = app(ProductImportProcessor::class);

        return $processor->handle($filePath);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

<?php

namespace App\Service\Imports;

use App\Jobs\Import\ReadProductImportFile;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProductImportProcessor
{
    /**
     * Create a pending import batch and queue the job that reads the file.
     * Parsing happens in the background; the returned batch is refreshed so
     * callers see its state after any synchronous processing.
     */
    public function handle(string $filePath): ImportBatch
    {
        $batch = ImportBatch::create([
            'filename' => basename($filePath),
            'format' => strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION)),
        ]);

        Log::channel('import')->info('Import batch queued', [
            'batch_id' => $batch->id,
            'filename' => $batch->filename,
            'format' => $batch->format,
        ]);

        $batchId = $batch->id;

        Bus::batch([new ReadProductImportFile($batchId, $filePath)])
            ->allowFailures()
            ->finally(function () use ($batchId) {
                // A file-level failure has already marked the batch failed; a
                // crashed last chunk completes it from its failed() hook.
                ImportBatch::find($batchId)?->completeIfFinished();
            })
            ->name("Import: {$batch->filename}")
            ->onQueue('imports')
            ->dispatch();

        return $batch->refresh();
    }
}

<?php

namespace App\Service\Imports;

use App\Jobs\Import\ProcessProductImportChunk;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProductImportProcessor
{
    public function __construct(private ImporterFactory $factory) {}

    public function handle(string $filePath, int $chunkSize = 500): ImportBatch // 50 for test
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        $batch = ImportBatch::create([
            'filename' => basename($filePath),
            'format'   => $extension,
        ]);
        $batch->markAsProcessing();

        Log::channel('import')->info('Import batch started', [
            'batch_id' => $batch->id,
            'filename' => $batch->filename,
            'format'   => $extension,
        ]);

        $strategy = $this->factory->make($extension);

        $busBatch = Bus::batch([])
            ->then(function () use ($batch) {
                $batch->refresh();
                $batch->markAsCompleted();
                Log::channel('import')->info('Import batch completed', [
                    'batch_id'       => $batch->id,
                    'total_rows'     => $batch->total_rows,
                    'processed_rows' => $batch->processed_rows,
                    'failed_rows'    => $batch->failed_rows,
                ]);
            })
            ->catch(function () use ($batch) {
                $batch->refresh();
                $batch->markAsFailed();
                Log::channel('import')->error('Import batch failed', [
                    'batch_id'    => $batch->id,
                    'failed_rows' => $batch->failed_rows,
                ]);
            })
            ->name("Import: {$batch->filename}")
            ->onQueue('imports')
            ->dispatch();

        $currentChunk = [];
        $totalRows = 0;

        foreach ($strategy->parse($filePath) as $row) {
            $currentChunk[] = $row;
            $totalRows++;

            if (count($currentChunk) === $chunkSize) {
                $busBatch->add(new ProcessProductImportChunk($batch->id, $currentChunk));
                $currentChunk = [];
            }
        }

        if (!empty($currentChunk)) {
            $busBatch->add(new ProcessProductImportChunk($batch->id, $currentChunk));
        }

        $batch->update(['total_rows' => $totalRows]);

        return $batch;
    }
}

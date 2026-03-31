<?php

namespace App\Service\Imports;

use App\Jobs\Import\ProcessProductImportChunk;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Bus;

class ProductImportProcessor
{
    public function __construct(private ImporterFactory $factory) {}

    public function handle(string $filePath, int $chunkSize = 50): ImportBatch // 50 for test
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        $batch = ImportBatch::create([
            'filename' => basename($filePath),
            'format'   => $extension,
        ]);
        $batch->markAsProcessing();

        $strategy = $this->factory->make($extension);

        $jobs = [];
        $currentChunk = [];
        $totalRows = 0;

        foreach ($strategy->parse($filePath) as $row) {
            $currentChunk[] = $row;
            $totalRows++;

            if (count($currentChunk) === $chunkSize) {
                $jobs[] = new ProcessProductImportChunk($batch->id, $currentChunk);
                $currentChunk = [];
            }

        }

        if (!empty($currentChunk)) {
            $jobs[] = new ProcessProductImportChunk($batch->id, $currentChunk);
        }

        $batch->update(['total_rows' => $totalRows]);

        Bus::batch($jobs)
            ->then(function () use ($batch) {
                $batch->markAsCompleted();
            })
            ->catch(function () use ($batch) {
                $batch->markAsFailed();
            })
            ->name("Import: {$batch->filename}")
            ->onQueue('imports')
            ->dispatch();

        return $batch;
    }
}

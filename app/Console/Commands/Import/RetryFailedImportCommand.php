<?php

namespace App\Console\Commands\Import;

use App\Enums\Import\ImportBatchRowStatus;
use App\Jobs\Import\ProcessProductImportChunk;
use App\Models\ImportBatch;
use Illuminate\Console\Command;

class RetryFailedImportCommand extends Command
{
    protected $signature = 'import:retry {batchId}';

    protected $description = 'Retry failed rows for a specific import batch';

    public function handle(): int
    {
        $batchId = $this->argument('batchId');

        $batch = ImportBatch::find($batchId);

        if (!$batch) {
            $this->error("Import batch not found with ID: {$batchId}");
            return Command::FAILURE;
        }

        $failedRows = $batch->failedRows()->get();

        if ($failedRows->isEmpty()) {
            $this->error("No failed rows found for batch ID: {$batchId}");
            return Command::FAILURE;
        }
        $batch->failedRows()->update(['status' => ImportBatchRowStatus::RETRYING]);

        $rows = $failedRows->pluck('raw_data')->toArray();

        $batch->update(['failed_rows' => 0]);

        ProcessProductImportChunk::dispatch($batch->id, $rows)
            ->onQueue('imports');

        $this->info("Retrying {$failedRows->count()} failed rows for batch ID: {$batchId}");

        return Command::SUCCESS;
    }
}

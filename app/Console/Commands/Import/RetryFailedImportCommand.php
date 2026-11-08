<?php

namespace App\Console\Commands\Import;

use App\Actions\Import\RetryFailedImportRows;
use App\Exceptions\ImportRetryRefusedException;
use App\Models\ImportBatch;
use Illuminate\Console\Command;

class RetryFailedImportCommand extends Command
{
    protected $signature = 'import:retry {batchId}';

    protected $description = 'Retry failed rows for a specific import batch';

    public function handle(RetryFailedImportRows $retry): int
    {
        $batchId = $this->argument('batchId');

        $batch = ImportBatch::find($batchId);

        if (! $batch) {
            $this->error("Import batch not found with ID: {$batchId}");

            return Command::FAILURE;
        }

        try {
            $count = $retry->handle($batch);
        } catch (ImportRetryRefusedException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Retrying {$count} failed rows for batch ID: {$batchId}");

        return Command::SUCCESS;
    }
}

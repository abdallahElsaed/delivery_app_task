<?php

namespace App\Actions\Import;

use App\Enums\Import\ImportBatchRowStatus;
use App\Exceptions\ImportRetryRefusedException;
use App\Jobs\Import\ProcessProductImportChunk;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry a finished import's retryable failed rows. Shared by `import:retry`
 * and the admin retry button so both follow the same rules.
 */
class RetryFailedImportRows
{
    /**
     * @return int the number of rows queued for retry
     *
     * @throws ImportRetryRefusedException when the batch can't be retried now
     */
    public function handle(ImportBatch $batch): int
    {
        [$failedRows, $previousStatus] = $this->claimFailedRows($batch->id);

        try {
            $this->dispatch($batch, $failedRows, $previousStatus);
        } catch (Throwable $e) {
            // Nothing was queued: put the rows and the batch back so the
            // retry can be run again.
            DB::transaction(function () use ($batch, $failedRows, $previousStatus) {
                ImportBatch::whereKey($batch->id)->increment('failed_rows', $failedRows->count(), ['status' => $previousStatus]);
                ImportBatchRow::whereKey($failedRows->modelKeys())->update(['status' => ImportBatchRowStatus::FAILED]);
            });

            throw $e;
        }

        Log::channel('import')->info('Import retry queued', ['batch_id' => $batch->id, 'rows' => $failedRows->count()]);

        return $failedRows->count();
    }

    /**
     * Mark the batch's retryable failed rows as retrying and the batch as
     * processing. The lock makes a second retry started at the same moment
     * see the batch as processing and be refused.
     *
     * @return array{EloquentCollection<int, ImportBatchRow>, string} the rows and the batch's status before the retry
     */
    private function claimFailedRows(int $batchId): array
    {
        return DB::transaction(function () use ($batchId) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batchId);

            if (! $batch->hasFinished()) {
                throw new ImportRetryRefusedException(
                    "Import batch {$batch->id} is still {$batch->status}; retry it once it has finished."
                );
            }

            $failedRows = $batch->failedRows()->get(['id', 'raw_data']);

            if ($failedRows->isEmpty()) {
                throw new ImportRetryRefusedException("No retryable failed rows found for batch ID: {$batch->id}");
            }

            $previousStatus = $batch->status;
            $batch->failedRows()->update(['status' => ImportBatchRowStatus::RETRYING]);
            $batch->decrement('failed_rows', $failedRows->count(), ['status' => 'processing']);

            return [$failedRows, $previousStatus];
        });
    }

    /**
     * Queue the rows in chunks, keyed by failed-row record ID: rows recorded
     * before Phase 2 can share a row number.
     */
    private function dispatch(ImportBatch $batch, Collection $failedRows, string $previousStatus): void
    {
        $batchId = $batch->id;
        $jobs = $failedRows->chunk(config('imports.chunk_size'))->map(fn (Collection $chunk) => new ProcessProductImportChunk(
            $batchId,
            $chunk->pluck('raw_data', 'id')->all(),
            $chunk->pluck('id', 'id')->all(),
        ));

        Bus::batch($jobs->all())
            ->allowFailures()
            ->finally(function () use ($batchId, $previousStatus) {
                // Back to the status it had, whatever the counters say: a
                // failed batch's file-level reason still applies, and older
                // batches can have counters short of their total. A crashed
                // last chunk records its rows after this, from failed().
                ImportBatch::whereKey($batchId)->update(['status' => $previousStatus, 'finished_at' => now()]);

                Log::channel('import')->info('Import retry finished', ['batch_id' => $batchId]);
            })
            ->name("Retry: {$batch->filename}")
            ->onQueue('imports')
            ->dispatch();
    }
}

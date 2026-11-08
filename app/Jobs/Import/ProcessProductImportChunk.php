<?php

namespace App\Jobs\Import;

use App\Enums\Import\ImportBatchRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantAttribute;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessProductImportChunk implements ShouldQueue
{
    use Batchable, DetectsConcurrencyErrors, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60];

    // Parallel chunks deadlock in MySQL (concurrent attribute upserts, and the
    // shared batch row), so transactions that lose a deadlock are re-run.
    private const TRANSACTION_ATTEMPTS = 5;

    // Declared rather than constructor-promoted so jobs queued before this
    // flag existed still unserialize with a value (false).
    private bool $isRetry = false;

    public function __construct(
        private int $importBatchId,
        private array $rows,
        bool $isRetry = false,
    ) {
        $this->isRetry = $isRetry;
    }

    public function handle(): void
    {
        // The file read failed part-way and cancelled the import.
        if ($this->batch()?->cancelled()) {
            return;
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $processedCount = 0;
        $failures = [];
        foreach ($this->rows as $rowNumber => $row) {
            try {
                $this->transaction(fn () => $this->importProduct($row));
                $processedCount++;
            } catch (\Throwable $e) {
                $failures[$rowNumber] = $e->getMessage();
                Log::channel('import')->error('Fail Job Row', [
                    'import_batch_id' => $this->importBatchId,
                    'row_number' => $rowNumber,
                    'raw_data' => $row,
                    'error_message' => $e->getMessage(),
                    'created_at' => now(),
                ]);
            }
        }

        // Record outcomes once, at the end: if this attempt crashes before
        // here, the next attempt starts clean instead of duplicating rows.
        $this->transaction(function () use ($processedCount, $failures) {
            $this->countRows($processedCount, count($failures));
            $this->recordFailures($failures);
        });

        Log::channel('import')->info('Job Finished', ['batch_id' => $this->importBatchId]);
        $this->loggingPerformance($startTime, $startMemory, $processedCount, count($failures));
    }

    /**
     * The chunk exhausted its attempts (e.g. the database went away): record
     * every row as a retryable failure so the import can finish and the rows
     * can be retried. Rows saved before the crash are re-saved idempotently.
     */
    public function failed(\Throwable $e): void
    {
        $reason = "Processing error: {$e->getMessage()}";

        $this->transaction(function () use ($reason) {
            $this->countRows(0, count($this->rows));
            $this->recordFailures(array_fill_keys(array_keys($this->rows), $reason));
        });

        Log::channel('import')->error('Chunk failed', [
            'batch_id' => $this->importBatchId,
            'rows' => count($this->rows),
            'error_message' => $e->getMessage(),
        ]);

        ImportBatch::find($this->importBatchId)?->completeIfFinished();
    }

    /**
     * Run a transaction, re-running it after a short random pause when it
     * loses a deadlock. Laravel's own retry re-runs immediately, so two
     * colliding workers tend to collide again.
     */
    private function transaction(callable $callback): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction($callback);

                return;
            } catch (\Throwable $e) {
                if ($attempt >= self::TRANSACTION_ATTEMPTS || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }
                usleep(random_int(10_000, 50_000) * $attempt);
            }
        }
    }

    /**
     * Update the batch counters. Called before inserting failed rows: their
     * foreign key share-locks the batch row, and taking the exclusive lock
     * first stops two chunks from deadlocking on the upgrade.
     */
    private function countRows(int $processed, int $failed): void
    {
        ImportBatch::whereKey($this->importBatchId)->incrementEach([
            'processed_rows' => $processed,
            'failed_rows' => $failed,
        ]);
    }

    /**
     * @param  array<int, string>  $failures  error message keyed by row number
     */
    private function recordFailures(array $failures): void
    {
        foreach ($failures as $rowNumber => $message) {
            ImportBatchRow::recordFailure($this->importBatchId, $rowNumber, $this->rows[$rowNumber], $message);
        }
    }

    private function importProduct(array $row): void
    {
        $product = Product::updateOrCreate(
            ['sku' => $row['product_sku']],
            [
                'name' => $row['product_name'],
                'status' => $row['product_status'],
                'currency' => $row['currency'],
            ]
        );
        $variant = ProductVariant::updateOrCreate(
            ['sku' => $row['variant_sku']],
            [
                'product_id' => $product->id,
                'name' => $row['variant_name'],
                'price' => $row['variant_price'],
                'stock' => $row['variant_stock'],
            ]
        );
        $attributes = $this->parseAttributes($row['attributes'] ?? '');

        if (! empty($attributes)) {
            VariantAttribute::upsert(
                array_map(fn ($attr) => [
                    'product_variant_id' => $variant->id,
                    'product_id' => $product->id,
                    'name' => $attr['attribute_key'],
                    'value' => $attr['attribute_value'],
                ], $attributes),
                uniqueBy: ['product_variant_id', 'name'],
                update: ['value']
            );
        }

        // Only a retry can have "retrying" rows to resolve; skip the JSON
        // lookup on normal imports.
        if ($this->isRetry) {
            ImportBatchRow::where('import_batch_id', $this->importBatchId)
                ->where('status', ImportBatchRowStatus::RETRYING)
                ->whereJsonContains('raw_data->variant_sku', $row['variant_sku'])
                ->update(['status' => ImportBatchRowStatus::RESOLVED]);
        }
    }

    private function parseAttributes(string $attributes): array
    {
        if (empty(trim($attributes))) {
            return [];
        }

        $result = [];

        foreach (explode('|', $attributes) as $pair) {
            $parts = explode(':', $pair, 2);

            if (count($parts) === 2) {
                $result[] = [
                    'attribute_key' => trim($parts[0]),
                    'attribute_value' => trim($parts[1]),
                ];
            }
        }

        return $result;
    }

    private function loggingPerformance(float $startTime, int $startMemory, int $processedCount, int $failedCount): void
    {
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $duration = round(($endTime - $startTime) * 1000, 2); // in milliseconds
        $memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2); // in MB
        $rowsPerSec = $processedCount > 0 ? round($processedCount / (($endTime - $startTime)), 2) : 0;

        Log::channel('import')->info('Chunk performance', [
            'batch_id' => $this->importBatchId,
            'processed' => $processedCount,
            'failed' => $failedCount,
            'duration_ms' => $duration,
            'memory_mb' => $memoryUsed,
            'rows_per_sec' => $rowsPerSec,
        ]);
    }
}

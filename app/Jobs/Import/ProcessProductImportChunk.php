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
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessProductImportChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $tries = 3;
    public array $backoff = [10, 60];

    public function __construct(
        private int $importBatchId,
        private array $rows,
    ) {}

    public function handle(): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $batch = ImportBatch::find($this->importBatchId);

        $processedCount = 0;
        $failedCount = 0;
        foreach ($this->rows as $index => $row) {
            try {
                $this->importProduct($row);
                $processedCount++;
                // logging Performance
            } catch (\Throwable $e) {
                $failedCount++;
                Log::channel('import')->error('Fail Job Row', [
                    'import_batch_id' => $this->importBatchId,
                    'row_number'      => $index + 1,
                    'raw_data'        => $row,
                    'error_message'   => $e->getMessage(),
                    'created_at'      => now(),
                ]);

                ImportBatchRow::create([
                    'import_batch_id' => $this->importBatchId,
                    'row_number'      => $index + 1,
                    'raw_data'        => $row,
                    'error_message'   => $e->getMessage(),
                    'status' => ImportBatchRowStatus::FAILED,
                    'created_at'      => now(),
                ]);
            }
        }

        $batch->increment('processed_rows', $processedCount);
        $batch->increment('failed_rows', $failedCount);
        Log::channel('import')->info('Job Finished', [$batch]);
        $this->loggingPerformance($startTime, $startMemory, $processedCount, $failedCount);
    }

    private function importProduct(array $row):void
    {
        $product = Product::updateOrCreate(
            ['sku' => $row['product_sku']],
            [
                'name'     => $row['product_name'],
                'status'   => $row['product_status'],
                'currency' => $row['currency'],
            ]
        );
        $variant = ProductVariant::updateOrCreate(
            ['sku' => $row['variant_sku']],
            [
                'product_id' => $product->id,
                'name'       => $row['variant_name'],
                'price'      => $row['variant_price'],
                'stock'      => $row['variant_stock'],
            ]
        );
        $attributes = $this->parseAttributes($row['attributes'] ?? '');

        if (!empty($attributes)) {
            VariantAttribute::upsert(
                array_map(fn($attr) => [
                    'product_variant_id'      => $variant->id,
                    'product_id' => $product->id,
                    'name'   => $attr['attribute_key'],
                    'value' => $attr['attribute_value'],
                ], $attributes),
                uniqueBy: ['product_variant_id', 'name'],
                update: ['value']
            );
        }
        ImportBatchRow::where('import_batch_id', $this->importBatchId)
            ->where('status', ImportBatchRowStatus::RETRYING)
            ->whereJsonContains('raw_data->variant_sku', $row['variant_sku'])
            ->update(['status' => ImportBatchRowStatus::RESOLVED]);
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
                    'attribute_key'   => trim($parts[0]),
                    'attribute_value' => trim($parts[1]),
                ];
            }
        }

        return $result;
    }

    private function loggingPerformance(float $startTime,int $startMemory,int $processedCount,int $failedCount): void
    {
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $duration   = round(($endTime - $startTime) * 1000, 2); // in milliseconds
        $memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2); // in MB
        $rowsPerSec = $processedCount > 0 ? round($processedCount / (($endTime - $startTime)), 2) : 0;

        Log::channel('import')->info('Chunk performance', [
            'batch_id'    => $this->importBatchId,
            'processed'   => $processedCount,
            'failed'      => $failedCount,
            'duration_ms' => $duration,
            'memory_mb'   => $memoryUsed,
            'rows_per_sec' => $rowsPerSec,
        ]);
    }
}

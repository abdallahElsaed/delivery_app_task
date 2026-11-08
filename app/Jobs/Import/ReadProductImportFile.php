<?php

namespace App\Jobs\Import;

use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Service\Imports\ImporterFactory;
use App\Service\Imports\MalformedImportRow;
use App\Service\Imports\ProductImportColumns;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * First job of an import's job batch: validates the file, counts its rows and
 * adds the chunk jobs to the same batch. Because chunks are added while this
 * job is still pending, the batch can't finish before every chunk is queued.
 */
class ReadProductImportFile implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    // A second attempt would queue every chunk and record every rejected row
    // again, so a crash fails the import instead (see failed()).
    public int $tries = 1;

    public function __construct(
        private int $importBatchId,
        private string $filePath,
    ) {}

    public function handle(ImporterFactory $factory): void
    {
        $batch = ImportBatch::findOrFail($this->importBatchId);

        try {
            $strategy = $factory->make($batch->format);
            $totalRows = $this->countRows($strategy->parse($this->filePath));
        } catch (InvalidArgumentException $e) {
            $this->rejectFile($batch, $e->getMessage());

            return;
        }

        $batch->update(['total_rows' => $totalRows]);
        $batch->markAsProcessing();

        $chunkSize = config('imports.chunk_size');
        $chunk = [];
        $rejectedCount = 0;

        // First accepted occurrence of each variant SKU (=> line) and product
        // SKU (=> line + details). Chunks run in parallel, so later rows that
        // repeat or contradict these are rejected here rather than racing.
        $variantLines = [];
        $products = [];

        foreach ($strategy->parse($this->filePath) as $line => $row) {
            // Neither kind of rejection can succeed on retry: the stored row
            // never changes, and in-file conflicts need the rest of the file.
            if ($row instanceof MalformedImportRow) {
                ImportBatchRow::recordFailure($batch->id, $line, $row->values, $row->reason, retryable: false);
                $rejectedCount++;

                continue;
            }

            $row = ProductImportColumns::normalise($row);
            $conflict = $this->findConflict($row, $variantLines, $products);

            if ($conflict !== null) {
                ImportBatchRow::recordFailure($batch->id, $line, $row, $conflict, retryable: false);
                $rejectedCount++;

                continue;
            }

            if (isset($row['variant_sku'])) {
                $variantLines[$row['variant_sku']] = $line;
            }
            if (isset($row['product_sku']) && ! isset($products[$row['product_sku']])) {
                $products[$row['product_sku']] = ['line' => $line]
                    + array_intersect_key($row, array_flip(ProductImportColumns::PRODUCT_DETAILS));
            }

            $chunk[$line] = $row;

            if (count($chunk) === $chunkSize) {
                $this->batch()->add(new ProcessProductImportChunk($batch->id, $chunk));
                $chunk = [];
            }
        }

        if (! empty($chunk)) {
            $this->batch()->add(new ProcessProductImportChunk($batch->id, $chunk));
        }

        $batch->increment('failed_rows', $rejectedCount);
    }

    /**
     * The read crashed (not a problem with the file itself, e.g. the database
     * went away). Fail the import and cancel the job batch so chunks already
     * queued skip their rows.
     */
    public function failed(Throwable $e): void
    {
        $this->batch()?->cancel();
        $this->rejectFile(ImportBatch::find($this->importBatchId), "Could not read the import file: {$e->getMessage()}");
    }

    /**
     * Count the data rows, checking the columns against the first row that
     * could be read as columns.
     *
     * @throws InvalidArgumentException when the file can't be imported at all
     */
    private function countRows(iterable $rows): int
    {
        $totalRows = 0;
        $columnsChecked = false;

        foreach ($rows as $row) {
            if (! $columnsChecked && is_array($row)) {
                $missing = ProductImportColumns::missingFrom(ProductImportColumns::normalise($row));
                if (! empty($missing)) {
                    throw new InvalidArgumentException('Missing required columns: '.implode(', ', $missing).'.');
                }
                $columnsChecked = true;
            }
            $totalRows++;
        }

        if ($totalRows === 0) {
            throw new InvalidArgumentException('Empty file: it contains no data rows.');
        }
        if (! $columnsChecked) {
            throw new InvalidArgumentException('No row in the file could be read as product columns.');
        }

        return $totalRows;
    }

    /**
     * Why a row contradicts an earlier accepted row of the same file, if it does.
     */
    private function findConflict(array $row, array $variantLines, array $products): ?string
    {
        $variantSku = $row['variant_sku'] ?? null;
        if ($variantSku !== null && isset($variantLines[$variantSku])) {
            return "Duplicate variant_sku {$variantSku} in this file (first seen at line {$variantLines[$variantSku]}).";
        }

        $productSku = $row['product_sku'] ?? null;
        if ($productSku === null || ! isset($products[$productSku])) {
            return null;
        }

        $differing = array_filter(
            ProductImportColumns::PRODUCT_DETAILS,
            fn (string $column) => ($row[$column] ?? null) !== ($products[$productSku][$column] ?? null),
        );

        return empty($differing)
            ? null
            : "Product {$productSku} has different ".implode(', ', $differing)." than at line {$products[$productSku]['line']}.";
    }

    private function rejectFile(?ImportBatch $batch, string $reason): void
    {
        $batch?->markAsFailed($reason);

        Log::channel('import')->error('Import file rejected', [
            'batch_id' => $this->importBatchId,
            'reason' => $reason,
        ]);
    }
}

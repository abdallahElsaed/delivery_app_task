<?php

namespace Tests\Feature\Imports;

use App\Enums\Import\ImportBatchRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The retry rules in .scratch/product-import-hardening/spec.md, through
 * `import:retry`. The admin retry button runs the same action.
 */
class RetryFailedImportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep test runs out of the real storage/logs/import.log.
        config(['logging.channels.import' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    }

    private function batchWithFailedRow(array $rawData, string $status = 'completed'): ImportBatch
    {
        $batch = ImportBatch::create([
            'filename' => 'products.csv',
            'format' => 'csv',
            'status' => $status,
            'total_rows' => 1,
            'processed_rows' => 0,
            'failed_rows' => 1,
        ]);

        ImportBatchRow::create([
            'import_batch_id' => $batch->id,
            'row_number' => 1,
            'raw_data' => $rawData,
            'error_message' => 'Original failure',
            'status' => ImportBatchRowStatus::FAILED,
            'created_at' => now(),
        ]);

        return $batch;
    }

    private function validRow(): array
    {
        return [
            'product_sku' => 'TSHIRT-001',
            'product_name' => 'T-Shirt',
            'product_status' => 'active',
            'currency' => 'USD',
            'variant_sku' => 'TSHIRT-001-RED-M',
            'variant_name' => 'Red M',
            'variant_price' => '19.99',
            'variant_stock' => '10',
            'attributes' => '',
        ];
    }

    public function test_unknown_batch_is_rejected(): void
    {
        $this->artisan('import:retry', ['batchId' => 999])
            ->expectsOutputToContain('Import batch not found with ID: 999')
            ->assertFailed();
    }

    public function test_batch_without_failed_rows_is_rejected(): void
    {
        $batch = ImportBatch::create(['filename' => 'products.csv', 'format' => 'csv', 'status' => 'completed']);

        $this->artisan('import:retry', ['batchId' => $batch->id])
            ->expectsOutputToContain("No retryable failed rows found for batch ID: {$batch->id}")
            ->assertFailed();
    }

    public function test_rows_marked_not_retryable_are_skipped_by_retry(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow());
        $batch->rows()->update(['retryable' => false]);

        $this->artisan('import:retry', ['batchId' => $batch->id])
            ->expectsOutputToContain("No retryable failed rows found for batch ID: {$batch->id}")
            ->assertFailed();

        $this->assertSame(ImportBatchRowStatus::FAILED->value, $batch->rows()->sole()->status);
        $this->assertFalse(Product::where('sku', 'TSHIRT-001')->exists());
    }

    public function test_retry_keeps_rows_that_cannot_be_retried_in_the_failed_count(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow());
        $batch->update(['total_rows' => 2, 'failed_rows' => 2]);
        ImportBatchRow::create([
            'import_batch_id' => $batch->id,
            'row_number' => 2,
            'raw_data' => $this->validRow(),
            'error_message' => 'Duplicate variant_sku in this file',
            'status' => ImportBatchRowStatus::FAILED,
            'retryable' => false,
            'created_at' => now(),
        ]);

        $this->artisan('import:retry', ['batchId' => $batch->id])->assertSuccessful();

        $batch->refresh();
        $this->assertSame(1, $batch->processed_rows);
        $this->assertSame(1, $batch->failed_rows);
    }

    public function test_retried_row_that_now_succeeds_is_resolved_and_counted_as_processed(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow());

        $this->artisan('import:retry', ['batchId' => $batch->id])
            ->expectsOutputToContain('Retrying 1 failed rows')
            ->assertSuccessful();

        $batch->refresh();
        $this->assertSame(1, $batch->processed_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->assertSame(ImportBatchRowStatus::RESOLVED->value, $batch->rows()->sole()->status);
        $this->assertTrue(Product::where('sku', 'TSHIRT-001')->exists());
    }

    /**
     * A failed batch stays failed: its file-level reason still applies, and
     * rows that were never read keep its counters short of the total.
     */
    #[DataProvider('finishedBatches')]
    public function test_retry_returns_the_batch_to_its_finished_status(string $status, ?string $reason, int $totalRows): void
    {
        $batch = $this->batchWithFailedRow($this->validRow(), status: $status);
        $batch->update(['error_message' => $reason, 'total_rows' => $totalRows, 'finished_at' => now()->subDay()]);

        $this->artisan('import:retry', ['batchId' => $batch->id])->assertSuccessful();

        $batch->refresh();
        $this->assertSame($status, $batch->status);
        $this->assertSame($reason, $batch->error_message);
        $this->assertTrue($batch->finished_at->isToday());
        $this->assertSame(1, $batch->processed_rows);
        $this->assertSame(0, $batch->failed_rows);
    }

    public static function finishedBatches(): array
    {
        return [
            'completed' => ['completed', null, 1],
            'failed while reading the file' => ['failed', 'Could not read the import file: Connection refused', 3],
        ];
    }

    public function test_each_retried_row_updates_its_own_record_without_duplicates(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow());
        $brokenRow = $this->validRow();
        unset($brokenRow['variant_sku']);
        $batch->update(['total_rows' => 2, 'failed_rows' => 2]);
        ImportBatchRow::create([
            'import_batch_id' => $batch->id,
            'row_number' => 2,
            'raw_data' => $brokenRow,
            'error_message' => 'Original failure',
            'status' => ImportBatchRowStatus::FAILED,
            'created_at' => now(),
        ]);

        $this->artisan('import:retry', ['batchId' => $batch->id])->assertSuccessful();

        $batch->refresh();
        $this->assertSame(1, $batch->processed_rows);
        $this->assertSame(1, $batch->failed_rows);

        [$fixed, $broken] = $batch->rows()->orderBy('row_number')->get();
        $this->assertSame(2, $batch->rows()->count());
        $this->assertSame(ImportBatchRowStatus::RESOLVED->value, $fixed->status);
        $this->assertSame(ImportBatchRowStatus::FAILED->value, $broken->status);
        $this->assertNotSame('Original failure', $broken->error_message);
        $this->assertTrue($broken->retryable);
    }

    public function test_retry_queues_rows_in_jobs_of_the_configured_chunk_size(): void
    {
        config(['imports.chunk_size' => 2]);
        $batch = $this->batchWithFailedRow($this->validRow());
        $batch->update(['total_rows' => 5, 'failed_rows' => 5]);
        foreach (range(2, 5) as $rowNumber) {
            ImportBatchRow::create([
                'import_batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'raw_data' => ['variant_sku' => "SKU-{$rowNumber}"] + $this->validRow(),
                'error_message' => 'Original failure',
                'status' => ImportBatchRowStatus::FAILED,
                'created_at' => now(),
            ]);
        }

        $this->artisan('import:retry', ['batchId' => $batch->id])
            ->expectsOutputToContain('Retrying 5 failed rows')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('job_batches')->sole()->total_jobs);
        $this->assertSame(5, $batch->refresh()->processed_rows);
        $this->assertSame('completed', $batch->status);
    }

    /**
     * Before Phase 2, row_number was a row's position within its chunk, so
     * older batches can hold several failed rows with the same number.
     */
    public function test_rows_sharing_a_row_number_are_each_retried(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow());
        $batch->update(['total_rows' => 2, 'failed_rows' => 2]);
        ImportBatchRow::create([
            'import_batch_id' => $batch->id,
            'row_number' => 1,
            'raw_data' => ['variant_sku' => 'TSHIRT-001-BLUE-M'] + $this->validRow(),
            'error_message' => 'Original failure',
            'status' => ImportBatchRowStatus::FAILED,
            'created_at' => now(),
        ]);

        $this->artisan('import:retry', ['batchId' => $batch->id])->assertSuccessful();

        $batch->refresh();
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->assertSame('completed', $batch->status);
        $this->assertSame(
            [ImportBatchRowStatus::RESOLVED->value, ImportBatchRowStatus::RESOLVED->value],
            $batch->rows()->pluck('status')->all(),
        );
    }

    /**
     * Batches marked completed before every chunk had finished (the
     * pre-Phase 2 race) have counters short of their total.
     */
    public function test_completed_batch_with_short_counters_is_completed_again_after_retry(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow());
        $batch->update(['total_rows' => 5, 'processed_rows' => 2]);

        $this->artisan('import:retry', ['batchId' => $batch->id])->assertSuccessful();

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(3, $batch->processed_rows);
    }

    public function test_retry_that_cannot_be_queued_leaves_the_batch_as_it_was(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow());
        // The job batch can't be stored, so nothing gets queued.
        Schema::drop('job_batches');

        try {
            $this->artisan('import:retry', ['batchId' => $batch->id])->run();
            $this->fail('The retry should have reported the queueing error.');
        } catch (QueryException) {
        }

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->failed_rows);
        $this->assertSame(ImportBatchRowStatus::FAILED->value, $batch->rows()->sole()->status);
    }

    #[DataProvider('runningStatuses')]
    public function test_retry_is_refused_while_the_batch_is_still_running(string $status): void
    {
        $batch = $this->batchWithFailedRow($this->validRow(), status: $status);

        $this->artisan('import:retry', ['batchId' => $batch->id])
            ->expectsOutputToContain("Import batch {$batch->id} is still {$status}")
            ->assertFailed();

        $this->assertSame(ImportBatchRowStatus::FAILED->value, $batch->rows()->sole()->status);
        $this->assertSame(1, $batch->refresh()->failed_rows);
        $this->assertFalse(Product::where('sku', 'TSHIRT-001')->exists());
    }

    public static function runningStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'processing' => ['processing'],
        ];
    }
}

<?php

namespace Tests\Feature\Imports;

use App\Enums\Import\ImportBatchRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * Characterisation tests for `import:retry` (Phase 0 of
 * .scratch/product-import-hardening/spec.md). Phase 3 replaces the
 * "..._currently_..." tests with the specified retry behaviour.
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
            ->expectsOutputToContain("No failed rows found for batch ID: {$batch->id}")
            ->assertFailed();
    }

    public function test_rows_marked_not_retryable_are_skipped_by_retry(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow());
        $batch->rows()->update(['retryable' => false]);

        $this->artisan('import:retry', ['batchId' => $batch->id])
            ->expectsOutputToContain("No failed rows found for batch ID: {$batch->id}")
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
     * Current behaviour: retry runs outside a job batch, so the import
     * batch's status is never touched. Phase 3 moves it through
     * processing → completed.
     */
    public function test_retry_currently_leaves_the_batch_status_unchanged(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow(), status: 'failed');

        $this->artisan('import:retry', ['batchId' => $batch->id])->assertSuccessful();

        $this->assertSame('failed', $batch->refresh()->status);
    }

    /**
     * Current behaviour: a row that fails again leaves the original record
     * stuck in "retrying" and adds a second "failed" record. Phase 3 reuses
     * the original record.
     */
    public function test_row_that_fails_again_is_currently_duplicated_and_left_retrying(): void
    {
        $row = $this->validRow();
        unset($row['variant_sku']);
        $batch = $this->batchWithFailedRow($row);

        $this->artisan('import:retry', ['batchId' => $batch->id])->assertSuccessful();

        $batch->refresh();
        $this->assertSame(0, $batch->processed_rows);
        $this->assertSame(1, $batch->failed_rows);
        $this->assertSame(
            [ImportBatchRowStatus::RETRYING->value, ImportBatchRowStatus::FAILED->value],
            $batch->rows()->orderBy('id')->pluck('status')->all(),
        );
    }

    /**
     * Current behaviour: retry is allowed while the original import is still
     * running. Phase 3 refuses it until the batch is completed or failed.
     */
    public function test_retry_is_currently_allowed_while_the_batch_is_processing(): void
    {
        $batch = $this->batchWithFailedRow($this->validRow(), status: 'processing');

        $this->artisan('import:retry', ['batchId' => $batch->id])->assertSuccessful();

        $this->assertSame(ImportBatchRowStatus::RESOLVED->value, $batch->rows()->sole()->status);
    }
}

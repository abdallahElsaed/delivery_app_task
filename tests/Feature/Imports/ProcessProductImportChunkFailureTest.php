<?php

namespace Tests\Feature\Imports;

use App\Enums\Import\ImportBatchRowStatus;
use App\Jobs\Import\ProcessProductImportChunk;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Handler\NullHandler;
use RuntimeException;
use Tests\TestCase;

/**
 * A chunk that exhausts its attempts can't be triggered through the commands
 * on the sync queue, so its failure hook is the one seam tested directly
 * (see Testing Decisions in .scratch/product-import-hardening/spec.md).
 */
class ProcessProductImportChunkFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep test runs out of the real storage/logs/import.log.
        config(['logging.channels.import' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    }

    public function test_chunk_that_exhausts_its_attempts_records_its_rows_as_retryable_failures(): void
    {
        $batch = ImportBatch::create([
            'filename' => 'products.csv',
            'format' => 'csv',
            'status' => 'processing',
            'total_rows' => 3,
        ]);
        $rows = [
            7 => ['product_sku' => 'TSHIRT-001', 'variant_sku' => 'TSHIRT-001-RED-M'],
            8 => ['product_sku' => 'MUG-001', 'variant_sku' => 'MUG-001-WHITE'],
        ];

        (new ProcessProductImportChunk($batch->id, $rows))->failed(new RuntimeException('Connection refused'));

        $batch->refresh();
        $this->assertSame(2, $batch->failed_rows);
        $this->assertSame('processing', $batch->status);

        $failed = $batch->rows()->orderBy('row_number')->get();
        $this->assertSame([7, 8], $failed->pluck('row_number')->all());
        $this->assertSame('MUG-001', $failed[1]->raw_data['product_sku']);
        $this->assertSame('Processing error: Connection refused', $failed[0]->error_message);
        $this->assertTrue($failed->every(fn ($row) => $row->retryable && $row->status === ImportBatchRowStatus::FAILED->value));
    }

    /**
     * Laravel runs the batch's finally callback before the failed job's own
     * failed() hook, so the hook must complete the batch when it accounts for
     * the last rows.
     */
    public function test_failure_hook_completes_the_batch_when_it_accounts_for_the_last_rows(): void
    {
        $batch = ImportBatch::create([
            'filename' => 'products.csv',
            'format' => 'csv',
            'status' => 'processing',
            'total_rows' => 3,
            'processed_rows' => 1,
        ]);
        $rows = [
            3 => ['product_sku' => 'TSHIRT-001', 'variant_sku' => 'TSHIRT-001-RED-M'],
            4 => ['product_sku' => 'MUG-001', 'variant_sku' => 'MUG-001-WHITE'],
        ];

        (new ProcessProductImportChunk($batch->id, $rows))->failed(new RuntimeException('Connection refused'));

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_retried_chunk_that_exhausts_its_attempts_fails_its_existing_records_again(): void
    {
        $batch = ImportBatch::create([
            'filename' => 'products.csv',
            'format' => 'csv',
            'status' => 'processing',
            'total_rows' => 2,
            'processed_rows' => 1,
        ]);
        $row = ['product_sku' => 'MUG-001', 'variant_sku' => 'MUG-001-WHITE'];
        $record = ImportBatchRow::recordFailure($batch->id, 5, $row, 'Original failure');
        $record->update(['status' => ImportBatchRowStatus::RETRYING]);

        (new ProcessProductImportChunk($batch->id, [$record->id => $row], [$record->id => $record->id]))
            ->failed(new RuntimeException('Connection refused'));

        $record = $batch->rows()->sole();
        $this->assertSame(ImportBatchRowStatus::FAILED->value, $record->status);
        $this->assertSame('Processing error: Connection refused', $record->error_message);
        $this->assertTrue($record->retryable);

        $batch->refresh();
        $this->assertSame(1, $batch->failed_rows);
        $this->assertSame('completed', $batch->status);
    }
}

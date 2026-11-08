<?php

namespace Tests\Feature\Imports;

use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Characterisation tests for `import:products` (Phase 0 of
 * .scratch/product-import-hardening/spec.md).
 *
 * Tests named "..._currently_..." pin down known-bad behaviour; the phase
 * named in each docblock replaces them with the specified behaviour.
 */
class ImportProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep test runs out of the real storage/logs/import.log.
        config(['logging.channels.import' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    }

    private function import(string $fixture): ImportBatch
    {
        $this->artisan('import:products', ['filePath' => base_path("tests/Fixtures/imports/{$fixture}")])
            ->assertSuccessful();

        return ImportBatch::latest('id')->firstOrFail();
    }

    public static function supportedFormats(): array
    {
        return [
            'csv' => ['products_without_attributes.csv', 'csv'],
            'json' => ['products_without_attributes.json', 'json'],
        ];
    }

    #[DataProvider('supportedFormats')]
    public function test_valid_file_is_imported_and_the_batch_completes(string $fixture, string $format): void
    {
        $batch = $this->import($fixture);

        $this->assertSame('completed', $batch->status);
        $this->assertSame($format, $batch->format);
        $this->assertSame($fixture, $batch->filename);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->assertNotNull($batch->started_at);
        $this->assertNotNull($batch->finished_at);

        $mug = Product::where('sku', 'MUG-001')->sole();
        $this->assertSame('Mug', $mug->name);
        $this->assertSame('inactive', $mug->status);
        $this->assertSame('EUR', $mug->currency);

        $variant = ProductVariant::where('sku', 'MUG-001-WHITE')->sole();
        $this->assertSame($mug->id, $variant->product_id);
        $this->assertSame('White', $variant->name);
        $this->assertEquals(7.50, $variant->price);
        $this->assertEquals(3, $variant->stock);
    }

    public function test_reimporting_a_file_updates_products_and_variants_instead_of_duplicating_them(): void
    {
        $this->import('products_without_attributes.csv');
        $this->import('products_without_attributes.csv');

        $this->assertSame(2, ImportBatch::count());
        $this->assertSame(2, Product::count());
        $this->assertSame(2, ProductVariant::count());
    }

    /**
     * Current behaviour on SQLite: the attribute upsert has no unique index to
     * target, so every row with attributes fails — after its product and
     * variant were already saved (no transaction). On MySQL the same code
     * inserts duplicate attributes instead. Phase 1 (unique index + per-row
     * transaction) replaces this test.
     */
    public function test_rows_with_attributes_currently_fail_after_saving_product_and_variant(): void
    {
        $batch = $this->import('products_with_attributes.csv');

        $this->assertSame('completed', $batch->status);
        $this->assertSame(0, $batch->processed_rows);
        $this->assertSame(2, $batch->failed_rows);
        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductVariant::count());
        $this->assertSame(0, VariantAttribute::count());
        $this->assertStringContainsString('ON CONFLICT', $batch->rows()->first()->error_message);
    }

    public function test_missing_file_is_rejected_without_creating_a_batch(): void
    {
        $this->artisan('import:products', ['filePath' => base_path('tests/Fixtures/imports/does_not_exist.csv')])
            ->expectsOutputToContain('File not found')
            ->assertFailed();

        $this->assertSame(0, ImportBatch::count());
    }

    /**
     * Current behaviour: the batch is marked "processing" before the format is
     * checked, so it stays "processing" forever. Phase 2 marks it failed.
     */
    public function test_unsupported_format_currently_leaves_the_batch_stuck_in_processing(): void
    {
        $this->artisan('import:products', ['filePath' => base_path('tests/Fixtures/imports/unsupported.txt')])
            ->expectsOutputToContain("File format 'txt' is not supported")
            ->assertFailed();

        $batch = ImportBatch::sole();
        $this->assertSame('processing', $batch->status);
        $this->assertNull($batch->finished_at);
    }

    /**
     * Current behaviour: no rows means no chunk jobs, so the batch's
     * completion callback never runs. Phase 2 marks it failed ("empty file").
     */
    public function test_header_only_file_currently_leaves_the_batch_stuck_in_processing(): void
    {
        $batch = $this->import('header_only.csv');

        $this->assertSame('processing', $batch->status);
        $this->assertSame(0, $batch->total_rows);
        $this->assertNull($batch->finished_at);
    }

    /**
     * Current behaviour: a CSV row whose column count differs from the header
     * is skipped without being counted or recorded. Phase 2 records it as a
     * failed row.
     */
    public function test_csv_row_with_wrong_column_count_is_currently_dropped_silently(): void
    {
        $batch = $this->import('wrong_column_count.csv');

        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->assertSame(0, $batch->rows()->count());
        $this->assertFalse(Product::where('sku', 'MUG-001')->exists());
    }

    /**
     * Current behaviour: the failed row is recorded, but its product is still
     * saved because rows are not wrapped in a transaction (Phase 1 changes
     * this). row_number is the row's 1-based position in its chunk; with a
     * single chunk that equals its data-row position. The chunk-relative
     * numbering bug only shows past the first chunk, which the command can't
     * reach until chunk size is configurable — Phase 2 tests cover it.
     */
    public function test_failing_row_is_recorded_but_its_product_is_currently_still_saved(): void
    {
        $batch = $this->import('second_row_missing_variant_sku.json');

        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->processed_rows);
        $this->assertSame(1, $batch->failed_rows);

        $failed = $batch->rows()->sole();
        $this->assertSame(2, $failed->row_number);
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('variant_sku', $failed->error_message);
        $this->assertSame('MUG-001', $failed->raw_data['product_sku']);

        $this->assertTrue(Product::where('sku', 'MUG-001')->exists());
        $this->assertFalse(ProductVariant::where('product_id', Product::where('sku', 'MUG-001')->value('id'))->exists());
    }

    /**
     * Current behaviour: row values are saved without validation. Phase 4
     * rejects unknown statuses and non-uppercase currencies.
     */
    public function test_invalid_status_and_currency_are_currently_saved_as_given(): void
    {
        $batch = $this->import('invalid_values.csv');

        $this->assertSame(1, $batch->processed_rows);
        $product = Product::sole();
        $this->assertSame('archived', $product->status);
        $this->assertSame('usd', $product->currency);
    }

    /**
     * Current behaviour: a variant SKU that already belongs to another product
     * is silently moved to the new product. Phase 4 fails the row instead.
     */
    public function test_variant_sku_used_by_another_product_is_currently_moved_to_it(): void
    {
        $batch = $this->import('variant_sku_in_two_products.csv');

        $this->assertSame(2, $batch->processed_rows);
        $variant = ProductVariant::where('sku', 'SHARED-VARIANT')->sole();
        $this->assertSame(Product::where('sku', 'HOODIE-001')->value('id'), $variant->product_id);
        $this->assertEquals(49.99, $variant->price);
    }
}

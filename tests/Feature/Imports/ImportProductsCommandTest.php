<?php

namespace Tests\Feature\Imports;

use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_configured_chunk_size_splits_the_import_into_that_many_rows_per_job(): void
    {
        config(['imports.chunk_size' => 1]);

        $batch = $this->import('products_without_attributes.csv');

        // One job reads the file, then one chunk job per row.
        $this->assertSame(3, DB::table('job_batches')->sole()->total_jobs);
        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->processed_rows);
    }

    public function test_reimporting_a_file_updates_products_and_variants_instead_of_duplicating_them(): void
    {
        $this->import('products_without_attributes.csv');
        $this->import('products_without_attributes.csv');

        $this->assertSame(2, ImportBatch::count());
        $this->assertSame(2, Product::count());
        $this->assertSame(2, ProductVariant::count());
    }

    public function test_reimporting_a_file_with_attributes_keeps_one_value_per_attribute(): void
    {
        $this->import('products_with_attributes.csv');
        $batch = $this->import('products_with_attributes.csv');

        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductVariant::count());

        $redM = ProductVariant::where('sku', 'TSHIRT-001-RED-M')->sole();
        $this->assertSame(
            ['color' => 'red', 'size' => 'M'],
            VariantAttribute::where('product_variant_id', $redM->id)->orderBy('name')->pluck('value', 'name')->all(),
        );
        $this->assertSame(4, VariantAttribute::count());
    }

    public function test_missing_file_is_rejected_without_creating_a_batch(): void
    {
        $this->artisan('import:products', ['filePath' => base_path('tests/Fixtures/imports/does_not_exist.csv')])
            ->expectsOutputToContain('File not found')
            ->assertFailed();

        $this->assertSame(0, ImportBatch::count());
    }

    public function test_unsupported_format_marks_the_batch_failed_with_the_reason(): void
    {
        $this->artisan('import:products', ['filePath' => base_path('tests/Fixtures/imports/unsupported.txt')])
            ->expectsOutputToContain("File format 'txt' is not supported")
            ->assertFailed();

        $batch = ImportBatch::sole();
        $this->assertSame('failed', $batch->status);
        $this->assertSame("File format 'txt' is not supported.", $batch->error_message);
        $this->assertNotNull($batch->finished_at);
        $this->assertSame(0, $batch->rows()->count());
    }

    public function test_headers_match_regardless_of_case_spacing_order_or_byte_order_mark(): void
    {
        $batch = $this->import('messy_headers.csv');

        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->processed_rows);

        $variant = ProductVariant::where('sku', 'MUG-001-WHITE')->sole();
        $this->assertSame('MUG-001', $variant->product->sku);
        $this->assertSame('inactive', $variant->product->status);
    }

    public function test_missing_required_columns_mark_the_batch_failed_before_any_row_is_saved(): void
    {
        $this->artisan('import:products', ['filePath' => base_path('tests/Fixtures/imports/missing_headers.csv')])
            ->expectsOutputToContain('Missing required columns: currency, attributes')
            ->assertFailed();

        $batch = ImportBatch::sole();
        $this->assertSame('failed', $batch->status);
        $this->assertSame('Missing required columns: currency, attributes.', $batch->error_message);
        $this->assertSame(0, Product::count());
    }

    public function test_file_without_data_rows_marks_the_batch_failed(): void
    {
        $this->artisan('import:products', ['filePath' => base_path('tests/Fixtures/imports/header_only.csv')])
            ->expectsOutputToContain('Empty file: it contains no data rows.')
            ->assertFailed();

        $batch = ImportBatch::sole();
        $this->assertSame('failed', $batch->status);
        $this->assertSame('Empty file: it contains no data rows.', $batch->error_message);
        $this->assertSame(0, $batch->total_rows);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_csv_row_with_wrong_column_count_is_recorded_as_failed_at_its_file_line(): void
    {
        $batch = $this->import('wrong_column_count.csv');

        $this->assertSame('completed', $batch->status);
        $this->assertSame(3, $batch->total_rows);
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(1, $batch->failed_rows);

        $failed = $batch->rows()->sole();
        $this->assertSame(3, $failed->row_number);
        $this->assertSame('Expected 9 columns, found 10.', $failed->error_message);
        $this->assertSame('MUG-001', $failed->raw_data[0]);
        $this->assertFalse($failed->retryable);
        $this->assertFalse(Product::where('sku', 'MUG-001')->exists());
    }

    public function test_line_numbers_count_line_breaks_inside_quoted_fields(): void
    {
        $batch = $this->import('multiline_field.csv');

        $this->assertSame(4, $batch->rows()->sole()->row_number);
    }

    public function test_file_where_no_row_matches_the_header_marks_the_batch_failed(): void
    {
        $this->artisan('import:products', ['filePath' => base_path('tests/Fixtures/imports/all_rows_malformed.csv')])
            ->assertFailed();

        $batch = ImportBatch::sole();
        $this->assertSame('failed', $batch->status);
        $this->assertSame('No row in the file could be read as product columns.', $batch->error_message);
        $this->assertSame(0, $batch->rows()->count());
    }

    public function test_json_file_that_is_not_a_list_of_rows_marks_the_batch_failed(): void
    {
        $this->artisan('import:products', ['filePath' => base_path('tests/Fixtures/imports/json_object_not_list.json')])
            ->assertFailed();

        $batch = ImportBatch::sole();
        $this->assertSame('failed', $batch->status);
        $this->assertSame('Invalid JSON file: expected a list of rows.', $batch->error_message);
    }

    public function test_failed_row_keeps_its_position_in_the_file_beyond_the_first_chunk(): void
    {
        config(['imports.chunk_size' => 1]);

        $batch = $this->import('second_row_missing_variant_sku.json');

        $this->assertSame(2, $batch->rows()->sole()->row_number);
    }

    /**
     * For JSON files, row_number is the row's 1-based position in the array.
     */
    public function test_failing_row_is_recorded_and_leaves_nothing_saved(): void
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

        $this->assertFalse(Product::where('sku', 'MUG-001')->exists());
        $this->assertSame(['TSHIRT-001'], Product::pluck('sku')->all());
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
     * Current behaviour: a later import that puts an existing variant SKU
     * under another product silently moves it. Phase 4 fails the row instead.
     */
    public function test_variant_sku_used_by_another_product_is_currently_moved_to_it(): void
    {
        $this->import('products_without_attributes.csv');
        $batch = $this->import('variant_sku_under_another_product.csv');

        $this->assertSame(1, $batch->processed_rows);
        $variant = ProductVariant::where('sku', 'TSHIRT-001-RED-M')->sole();
        $this->assertSame(Product::where('sku', 'HOODIE-001')->value('id'), $variant->product_id);
        $this->assertEquals(49.99, $variant->price);
    }

    public function test_repeated_variant_sku_in_one_file_fails_later_rows_without_retry(): void
    {
        $batch = $this->import('variant_sku_in_two_products.csv');

        $this->assertSame(1, $batch->processed_rows);
        $this->assertSame(1, $batch->failed_rows);

        $failed = $batch->rows()->sole();
        $this->assertSame(3, $failed->row_number);
        $this->assertSame('Duplicate variant_sku SHARED-VARIANT in this file (first seen at line 2).', $failed->error_message);
        $this->assertFalse($failed->retryable);

        $variant = ProductVariant::where('sku', 'SHARED-VARIANT')->sole();
        $this->assertSame('TSHIRT-001', $variant->product->sku);
        $this->assertEquals(19.99, $variant->price);
        $this->assertFalse(Product::where('sku', 'HOODIE-001')->exists());
    }

    public function test_conflicting_product_details_in_one_file_fail_later_rows_without_retry(): void
    {
        $batch = $this->import('conflicting_product_details.csv');

        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(1, $batch->failed_rows);

        $failed = $batch->rows()->sole();
        $this->assertSame(3, $failed->row_number);
        $this->assertSame('Product TSHIRT-001 has different product_name than at line 2.', $failed->error_message);
        $this->assertFalse($failed->retryable);

        $this->assertSame('T-Shirt', Product::sole()->name);
        $this->assertFalse(ProductVariant::where('sku', 'TSHIRT-001-BLU-L')->exists());
    }
}

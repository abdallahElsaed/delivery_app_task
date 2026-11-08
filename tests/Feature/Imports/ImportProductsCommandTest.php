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
 * Behaviour of `import:products`, as specified in
 * .scratch/product-import-hardening/spec.md.
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

    private function attributesOf(string $variantSku): array
    {
        return ProductVariant::where('sku', $variantSku)->sole()
            ->attributes()->orderBy('name')->pluck('value', 'name')->all();
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

    public function test_file_replaces_the_attributes_of_the_variants_it_contains(): void
    {
        $this->import('products_with_attributes.csv');
        $this->import('changed_attributes.csv');

        $this->assertSame(['color' => 'green', 'material' => 'cotton'], $this->attributesOf('TSHIRT-001-RED-M'));
        $this->assertSame(['color' => 'blue', 'size' => 'L'], $this->attributesOf('TSHIRT-001-BLU-L'));
    }

    public function test_empty_attributes_cell_removes_the_variants_attributes(): void
    {
        $this->import('products_with_attributes.csv');
        $this->import('products_without_attributes.csv');

        $this->assertSame([], $this->attributesOf('TSHIRT-001-RED-M'));
        $this->assertSame(['color' => 'blue', 'size' => 'L'], $this->attributesOf('TSHIRT-001-BLU-L'));
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

    public function test_rows_at_the_limits_of_every_rule_are_imported(): void
    {
        $batch = $this->import('invalid_values.csv');

        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(17, $batch->failed_rows);
        $this->assertSame('draft', ProductVariant::where('sku', 'LIMIT-1')->sole()->product->status);
        $this->assertSame(['size' => 'M', 'url' => 'http://x.test'], $this->attributesOf('LIMIT-2-V'));
    }

    public static function brokenRules(): array
    {
        return [
            'missing name' => [4, 'The product_name field is required.'],
            'sku too long' => [5, 'The product_sku field must not be greater than 255 characters.'],
            'unknown status' => [6, 'The product_status must be active, inactive or draft.'],
            'lowercase currency' => [7, 'The currency must be 3 uppercase letters.'],
            'three decimals' => [8, 'The variant_price field must have 0-2 decimal places.'],
            'price too high' => [9, 'The variant_price field must not be greater than 99999999.99.'],
            'negative price' => [10, 'The variant_price field must be at least 0.'],
            'price not a number' => [11, 'The variant_price field must be a number.'],
            'fractional stock' => [12, 'The variant_stock field must be an integer.'],
            'negative stock' => [13, 'The variant_stock field must be at least 0.'],
            'attribute without colon' => [14, "Attribute 'color-red' is not a key:value pair with a key and a value."],
            'attribute without value' => [15, "Attribute 'color:' is not a key:value pair with a key and a value."],
            'attribute without key' => [16, "Attribute ':red' is not a key:value pair with a key and a value."],
            'attribute value too long' => [17, 'Attribute note is longer than 255 characters.'],
            'attribute key twice' => [18, 'Attribute color appears more than once.'],
            'stock beyond the column' => [19, 'The variant_stock field must not be greater than 2147483647.'],
            'currency with a trailing newline' => [20, 'The currency must be 3 uppercase letters.'],
        ];
    }

    #[DataProvider('brokenRules')]
    public function test_row_that_breaks_a_rule_fails_with_the_reason_and_saves_nothing(int $line, string $reason): void
    {
        $batch = $this->import('invalid_values.csv');

        $failed = $batch->rows()->where('row_number', $line)->sole();
        $this->assertSame($reason, $failed->error_message);
        $this->assertTrue($failed->retryable);
        $this->assertFalse(ProductVariant::where('sku', $failed->raw_data['variant_sku'])->exists());
    }

    public function test_variant_sku_that_belongs_to_another_product_fails_the_row(): void
    {
        $this->import('products_without_attributes.csv');
        $batch = $this->import('variant_sku_under_another_product.csv');

        $this->assertSame(0, $batch->processed_rows);
        $this->assertSame(1, $batch->failed_rows);

        $failed = $batch->rows()->sole();
        $this->assertSame(2, $failed->row_number);
        $this->assertSame('Variant TSHIRT-001-RED-M already belongs to product TSHIRT-001.', $failed->error_message);
        $this->assertTrue($failed->retryable);

        $variant = ProductVariant::where('sku', 'TSHIRT-001-RED-M')->sole();
        $this->assertSame('TSHIRT-001', $variant->product->sku);
        $this->assertEquals(19.99, $variant->price);
        $this->assertFalse(Product::where('sku', 'HOODIE-001')->exists());
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

<?php

namespace Tests\Feature\Imports;

use App\Enums\Import\ImportBatchRowStatus;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\ImportBatches\Pages\CreateImportBatch;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Characterisation tests for the import admin pages (Phase 0 of
 * .scratch/product-import-hardening/spec.md). Only UI-specific behaviour is
 * covered here; the pipeline itself is covered through the commands.
 */
class ImportBatchAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep test runs out of the real storage/logs/import.log.
        config(['logging.channels.import' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);

        $this->actingAs(User::factory()->create());

        // Keep uploads out of the real storage/app/private/imports.
        Storage::fake('local');
    }

    public function test_uploading_a_file_creates_a_batch_and_redirects_to_it(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            file_get_contents(base_path('tests/Fixtures/imports/products_without_attributes.csv')),
        );

        $component = Livewire::test(CreateImportBatch::class)
            ->fillForm(['file' => $file])
            ->call('create')
            ->assertHasNoFormErrors();

        $batch = ImportBatch::sole();
        $component->assertRedirect(ImportBatchResource::getUrl('view', ['record' => $batch]));
        $this->assertSame('products.csv', $batch->filename);
        $this->assertSame(2, $batch->total_rows);
    }

    public function test_two_uploads_with_the_same_name_keep_their_own_files(): void
    {
        foreach (['products_without_attributes.csv', 'products_with_attributes.csv'] as $fixture) {
            Livewire::test(CreateImportBatch::class)
                ->fillForm(['file' => UploadedFile::fake()->createWithContent(
                    'products.csv',
                    file_get_contents(base_path("tests/Fixtures/imports/{$fixture}")),
                )])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertCount(2, Storage::disk('local')->allFiles('imports'));
        $this->assertSame(['products.csv', 'products.csv'], ImportBatch::orderBy('id')->pluck('filename')->all());
    }

    public static function rejectedUploads(): array
    {
        return [
            'not csv or json' => [fn () => UploadedFile::fake()->createWithContent(
                'products.txt',
                file_get_contents(base_path('tests/Fixtures/imports/products_without_attributes.csv')),
            ), 'The import File field must have one of the following extensions: csv, json.'],
            'over 50 MB' => [
                fn () => UploadedFile::fake()->create('products.csv', 51201, 'text/csv'),
                'The import File field must not be greater than 51200 kilobytes.',
            ],
        ];
    }

    #[DataProvider('rejectedUploads')]
    public function test_upload_that_breaks_a_limit_is_rejected_without_creating_a_batch(\Closure $makeFile, string $reason): void
    {
        $component = Livewire::test(CreateImportBatch::class)
            ->fillForm(['file' => $makeFile()])
            ->call('create')
            ->assertHasFormErrors(['file']);

        $this->assertSame([$reason], $component->errors()->get('data.file'));

        $this->assertSame(0, ImportBatch::count());
    }

    public function test_failed_batch_page_shows_why_it_failed(): void
    {
        $batch = ImportBatch::create([
            'filename' => 'products.txt',
            'format' => 'txt',
            'status' => 'failed',
            'error_message' => "File format 'txt' is not supported.",
        ]);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee("File format 'txt' is not supported.");
    }

    public function test_retry_action_is_hidden_when_no_failed_row_can_be_retried(): void
    {
        $batch = $this->batchWithFailedRow(status: 'completed', retryable: false);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionHidden('retryFailedRows');
    }

    #[DataProvider('runningStatuses')]
    public function test_retry_action_is_hidden_while_the_batch_is_running(string $status): void
    {
        $batch = $this->batchWithFailedRow(status: $status);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionHidden('retryFailedRows');
    }

    public static function runningStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'processing' => ['processing'],
        ];
    }

    public function test_retry_action_retries_the_failed_rows_of_a_finished_batch(): void
    {
        $batch = $this->batchWithFailedRow(status: 'completed');

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionVisible('retryFailedRows')
            ->callAction('retryFailedRows')
            ->assertNotified('Retrying 1 failed rows.');

        $this->assertSame(ImportBatchRowStatus::RESOLVED->value, $batch->rows()->sole()->status);
        $this->assertSame('completed', $batch->refresh()->status);
    }

    private function batchWithFailedRow(string $status, bool $retryable = true): ImportBatch
    {
        $batch = ImportBatch::create([
            'filename' => 'products.csv',
            'format' => 'csv',
            'status' => $status,
            'total_rows' => 1,
            'failed_rows' => 1,
        ]);

        ImportBatchRow::recordFailure($batch->id, 2, [
            'product_sku' => 'TSHIRT-001',
            'product_name' => 'T-Shirt',
            'product_status' => 'active',
            'currency' => 'USD',
            'variant_sku' => 'TSHIRT-001-RED-M',
            'variant_name' => 'Red M',
            'variant_price' => '19.99',
            'variant_stock' => '10',
            'attributes' => '',
        ], 'Original failure', $retryable);

        return $batch;
    }
}

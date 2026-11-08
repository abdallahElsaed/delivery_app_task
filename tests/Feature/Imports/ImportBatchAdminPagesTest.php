<?php

namespace Tests\Feature\Imports;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\ImportBatches\Pages\CreateImportBatch;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * Characterisation tests for the import admin pages (Phase 0 of
 * .scratch/product-import-hardening/spec.md). Only UI-specific behaviour is
 * covered here; the pipeline itself is covered through the commands.
 */
class ImportBatchAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadName;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep test runs out of the real storage/logs/import.log.
        config(['logging.channels.import' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);

        $this->actingAs(User::factory()->create());

        // The create page reads uploads from the real local disk, so use a
        // unique name and remove it afterwards.
        $this->uploadName = 'phase0-test-'.uniqid().'.csv';
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->delete("imports/{$this->uploadName}");

        parent::tearDown();
    }

    public function test_uploading_a_file_creates_a_batch_and_redirects_to_it(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            $this->uploadName,
            file_get_contents(base_path('tests/Fixtures/imports/products_without_attributes.csv')),
        );

        $component = Livewire::test(CreateImportBatch::class)
            ->fillForm(['file' => $file])
            ->call('create')
            ->assertHasNoFormErrors();

        $batch = ImportBatch::sole();
        $component->assertRedirect(ImportBatchResource::getUrl('view', ['record' => $batch]));
        $this->assertSame($this->uploadName, $batch->filename);
        $this->assertSame(2, $batch->total_rows);
    }

    public function test_retry_action_is_hidden_when_nothing_failed(): void
    {
        $batch = ImportBatch::create(['filename' => 'products.csv', 'format' => 'csv', 'status' => 'completed']);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionHidden('retryFailedRows');
    }

    /**
     * Current behaviour: the retry button shows as soon as failed_rows > 0,
     * even while the import is still running. Phase 3 hides it until the
     * batch is completed or failed.
     */
    public function test_retry_action_is_currently_visible_while_the_batch_is_processing(): void
    {
        $batch = ImportBatch::create([
            'filename' => 'products.csv',
            'format' => 'csv',
            'status' => 'processing',
            'failed_rows' => 1,
        ]);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionVisible('retryFailedRows');
    }
}

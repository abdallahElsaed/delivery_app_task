<?php

namespace App\Console\Commands\Import;

use App\Service\Imports\ProductImportProcessor;
use Illuminate\Console\Command;

class ImportProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:products {filePath}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Products from file';

    /**
     * Execute the console command.
     */
    public function handle(ProductImportProcessor $processor): int
    {
        $filePath = $this->argument('filePath');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        try {
            $batch = $processor->handle($filePath);

            $this->info('Import dispatched successfully.');
            $this->info("Batch ID: {$batch->id}");
            $this->info("Total rows queued: {$batch->total_rows}");

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

}

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

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return Command::FAILURE;
        }

        try {
            $batch = $processor->handle($filePath);

            // On a synchronous queue the file has already been read by now.
            if ($batch->isFailed()) {
                $this->error("Import failed: {$batch->error_message}");
                $this->info("Batch ID: {$batch->id}");

                return Command::FAILURE;
            }

            $this->info('Import queued successfully.');
            $this->info("Batch ID: {$batch->id}");

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}

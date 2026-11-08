<?php

namespace App\Console\Commands\Import;

use App\Service\Imports\ProductImportProcessor;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            $batch = $processor->handle($this->storeCopy($filePath));

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

    /**
     * Copy the file into the imports directory, as the upload page stores its
     * files, so the background job doesn't depend on the original staying put.
     * Each copy gets its own folder so files with the same name never clash.
     *
     * @return string the copy's absolute path
     */
    private function storeCopy(string $filePath): string
    {
        $disk = Storage::disk('local');
        $storedPath = $disk->putFileAs('imports/'.Str::ulid(), new File($filePath), basename($filePath));

        if ($storedPath === false) {
            throw new \RuntimeException("Could not copy {$filePath} into the imports directory.");
        }

        return $disk->path($storedPath);
    }
}

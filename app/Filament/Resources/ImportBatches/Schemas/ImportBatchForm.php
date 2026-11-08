<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ImportBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label('Import File')
                    ->required()
                    ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain', 'application/json'])
                    // CSV files are often detected as text/plain, so the MIME
                    // check alone would let any text file through.
                    ->rule('extensions:csv,json')
                    ->maxSize(51200) // KB, i.e. 50 MB
                    ->disk('local')
                    // A folder per upload, so files with the same name never
                    // overwrite one that a pending import has yet to read.
                    ->directory(fn (): string => 'imports/'.Str::ulid())
                    ->preserveFilenames(),
            ]);
    }
}

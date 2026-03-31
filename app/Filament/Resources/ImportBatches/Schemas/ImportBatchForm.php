<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;

class ImportBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label('Import File')
                    ->required()
                    ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain'])
                    ->disk('local')
                    ->directory('imports')
                    ->preserveFilenames(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ImportBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('filename')
                    ->required(),
                TextInput::make('format')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('total_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('processed_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('failed_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('finished_at'),
            ]);
    }
}

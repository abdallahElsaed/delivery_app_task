<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ImportBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('filename'),
                TextEntry::make('format'),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        'pending' => 'gray',
                        default => 'gray',
                    }),
                TextEntry::make('total_rows')
                    ->numeric(),
                TextEntry::make('processed_rows')
                    ->numeric(),
                TextEntry::make('failed_rows')
                    ->numeric(),
                TextEntry::make('started_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('finished_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}

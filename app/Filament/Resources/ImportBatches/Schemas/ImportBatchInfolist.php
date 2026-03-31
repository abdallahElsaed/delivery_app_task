<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use App\Models\ImportBatch;
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
                TextEntry::make('progress')
                    ->label('Progress')
                    ->state(function (ImportBatch $record): string {
                        if ($record->total_rows === 0) {
                            return '0%';
                        }
                        $processed = $record->processed_rows + $record->failed_rows;
                        $percent = round(($processed / $record->total_rows) * 100);
                        return "{$percent}% ({$processed}/{$record->total_rows})";
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

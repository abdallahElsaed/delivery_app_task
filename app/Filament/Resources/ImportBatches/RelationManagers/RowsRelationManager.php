<?php

namespace App\Filament\Resources\ImportBatches\RelationManagers;

use App\Enums\Import\ImportBatchRowStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Failed & Retrying Rows';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', ImportBatchRowStatus::RESOLVED))
            ->columns([
                TextColumn::make('row_number')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'failed' => 'danger',
                        'retrying' => 'warning',
                        'resolved' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('error_message')
                    ->wrap()
                    ->limit(100),
                TextColumn::make('raw_data')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state)
                    ->wrap()
                    ->limit(200),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('row_number');
    }
}

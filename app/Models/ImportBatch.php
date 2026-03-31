<?php

namespace App\Models;

use App\Enums\Import\ImportBatchRowStatus;
use App\Models\ImportBatchRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = [
        'filename',
        'format',
        'status',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class);
    }

    public function failedRows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class)->where('status', ImportBatchRowStatus::FAILED);
    }
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing', 'started_at' => now()]);
    }

    public function markAsCompleted(): void
    {
        $this->update(['status' => 'completed', 'finished_at' => now()]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed', 'finished_at' => now()]);
    }
}

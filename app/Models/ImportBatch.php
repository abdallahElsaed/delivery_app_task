<?php

namespace App\Models;

use App\Enums\Import\ImportBatchRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class ImportBatch extends Model
{
    protected $fillable = [
        'filename',
        'format',
        'status',
        'error_message',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return HasMany<ImportBatchRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class);
    }

    /** @return HasMany<ImportBatchRow, $this> */
    public function failedRows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class)
            ->where('status', ImportBatchRowStatus::FAILED)
            ->where('retryable', true);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing', 'started_at' => now()]);
    }

    public function markAsCompleted(): void
    {
        $this->update(['status' => 'completed', 'finished_at' => now()]);
    }

    public function markAsFailed(?string $reason = null): void
    {
        $this->update(['status' => 'failed', 'error_message' => $reason, 'finished_at' => now()]);
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Completed or failed: nothing is queued or running for this batch.
     */
    public function hasFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /**
     * Mark a processing batch completed once every row is accounted for.
     * Called from the job batch's finally callback and from a crashed chunk's
     * failed() hook, because Laravel runs finally before that hook.
     */
    public function completeIfFinished(): bool
    {
        $this->refresh();

        if (! $this->isProcessing() || $this->total_rows === 0
            || $this->processed_rows + $this->failed_rows < $this->total_rows) {
            return false;
        }

        $this->markAsCompleted();

        Log::channel('import')->info('Import batch completed', [
            'batch_id' => $this->id,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'failed_rows' => $this->failed_rows,
        ]);

        return true;
    }
}

<?php

namespace App\Models;

use App\Enums\Import\ImportBatchRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatchRow extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'raw_data',
        'error_message',
        'status',
        'retryable',
        'created_at',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'retryable' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public static function recordFailure(int $importBatchId, int $rowNumber, array $rawData, string $reason, bool $retryable = true): self
    {
        return self::create([
            'import_batch_id' => $importBatchId,
            'row_number' => $rowNumber,
            'raw_data' => $rawData,
            'error_message' => $reason,
            'status' => ImportBatchRowStatus::FAILED,
            'retryable' => $retryable,
            'created_at' => now(),
        ]);
    }
}

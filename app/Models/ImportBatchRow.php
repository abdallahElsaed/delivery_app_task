<?php

namespace App\Models;

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
        'created_at',
    ];

    protected $casts = [
        'raw_data'   => 'array',
        'created_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}

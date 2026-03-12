<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiExport extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'symbols',
        'indicators',
        'options',
        'file_disk',
        'file_path',
        'file_name',
        'error_message',
        'started_at',
        'generated_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'symbols' => 'array',
            'indicators' => 'array',
            'options' => 'array',
            'started_at' => 'datetime',
            'generated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

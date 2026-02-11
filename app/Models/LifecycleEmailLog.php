<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifecycleEmailLog extends Model
{
    protected $fillable = [
        'user_id',
        'event_key',
        'sent_at',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


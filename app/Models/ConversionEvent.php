<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionEvent extends Model
{
    protected $fillable = [
        'user_id',
        'event_type',
        'event_key',
        'authority',
        'occurred_at',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'properties' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

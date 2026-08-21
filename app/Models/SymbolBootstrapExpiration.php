<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SymbolBootstrapExpiration extends Model
{
    protected $fillable = [
        'work_run_id',
        'expiration_date',
        'fast_scope',
        'fast_ready_at',
        'fill_ready_at',
    ];

    protected function casts(): array
    {
        return [
            'expiration_date' => 'immutable_date',
            'fast_scope' => 'boolean',
            'fast_ready_at' => 'immutable_datetime',
            'fill_ready_at' => 'immutable_datetime',
        ];
    }

    public function bootstrapRun(): BelongsTo
    {
        return $this->belongsTo(SymbolBootstrapRun::class, 'work_run_id', 'work_run_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SymbolBootstrapHead extends Model
{
    protected $fillable = [
        'symbol',
        'session_date',
        'purpose',
        'current_work_run_id',
        'current_generation',
        'current_full_ready_at',
        'previous_work_run_id',
        'previous_generation',
        'previous_full_ready_at',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'immutable_date',
            'current_generation' => 'integer',
            'previous_generation' => 'integer',
            'current_full_ready_at' => 'immutable_datetime',
            'previous_full_ready_at' => 'immutable_datetime',
        ];
    }

    public function currentWorkRun(): BelongsTo
    {
        return $this->belongsTo(WorkRun::class, 'current_work_run_id');
    }

    public function previousWorkRun(): BelongsTo
    {
        return $this->belongsTo(WorkRun::class, 'previous_work_run_id');
    }
}

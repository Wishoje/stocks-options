<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SymbolBootstrapRun extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'work_run_id';

    protected $keyType = 'string';

    protected $fillable = [
        'work_run_id',
        'symbol',
        'purpose',
        'session_date',
        'generation',
        'status',
        'current_phase',
        'fast_horizon_days',
        'fill_horizon_days',
        'catalog_horizon_start',
        'catalog_horizon_end',
        'catalog_source',
        'catalog_source_asof',
        'expected_expirations_hash',
        'expected_count',
        'fast_expected_count',
        'fast_ready_count',
        'fill_ready_count',
        'catalog_frozen_at',
        'heartbeat_at',
        'full_ready_at',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'immutable_date',
            'catalog_horizon_start' => 'immutable_date',
            'catalog_horizon_end' => 'immutable_date',
            'catalog_source_asof' => 'immutable_datetime',
            'catalog_frozen_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'full_ready_at' => 'immutable_datetime',
            'generation' => 'integer',
            'fast_horizon_days' => 'integer',
            'fill_horizon_days' => 'integer',
            'expected_count' => 'integer',
            'fast_expected_count' => 'integer',
            'fast_ready_count' => 'integer',
            'fill_ready_count' => 'integer',
        ];
    }

    public function workRun(): BelongsTo
    {
        return $this->belongsTo(WorkRun::class, 'work_run_id');
    }

    public function expirations(): HasMany
    {
        return $this->hasMany(SymbolBootstrapExpiration::class, 'work_run_id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(SymbolBootstrapPhase::class, 'work_run_id');
    }

    protected function asDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return parent::asDateTime($value)->toImmutable()->utc();
    }
}

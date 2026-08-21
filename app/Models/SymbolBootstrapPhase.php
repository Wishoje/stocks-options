<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SymbolBootstrapPhase extends Model
{
    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'work_run_id',
        'phase',
        'status',
        'queue_connection',
        'queue',
        'delivery_token',
        'orchestration_token',
        'orchestration_attempt',
        'orchestration_reserved_at',
        'orchestration_dispatched_at',
        'dispatch_attempts',
        'attempt',
        'dispatching_at',
        'dispatched_at',
        'next_dispatch_at',
        'started_at',
        'heartbeat_at',
        'lease_expires_at',
        'retry_not_before',
        'completed_at',
        'failed_at',
        'error_category',
        'error_code',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'dispatching_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'orchestration_reserved_at' => 'immutable_datetime',
            'orchestration_dispatched_at' => 'immutable_datetime',
            'next_dispatch_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'retry_not_before' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'outcome' => 'array',
            'dispatch_attempts' => 'integer',
            'attempt' => 'integer',
            'orchestration_attempt' => 'integer',
        ];
    }

    public function bootstrapRun(): BelongsTo
    {
        return $this->belongsTo(SymbolBootstrapRun::class, 'work_run_id', 'work_run_id');
    }

    protected function asDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return parent::asDateTime($value)->toImmutable()->utc();
    }
}

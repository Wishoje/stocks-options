<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkRun extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var string[] */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    protected $fillable = [
        'requested_by_user_id',
        'kind',
        'provider',
        'symbol',
        'scope_hash',
        'slot_key',
        'generation',
        'status',
        'queue_connection',
        'queue',
        'parameters',
        'delivery_token',
        'orchestration_token',
        'orchestration_reserved_at',
        'orchestration_dispatched_at',
        'dispatch_attempts',
        'attempt',
        'orchestration_attempt',
        'requested_at',
        'dispatching_at',
        'dispatched_at',
        'next_dispatch_at',
        'started_at',
        'heartbeat_at',
        'lease_expires_at',
        'reusable_until',
        'retry_not_before',
        'completed_at',
        'failed_at',
        'error_category',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'requested_at' => 'immutable_datetime',
            'dispatching_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'orchestration_reserved_at' => 'immutable_datetime',
            'orchestration_dispatched_at' => 'immutable_datetime',
            'next_dispatch_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'reusable_until' => 'immutable_datetime',
            'retry_not_before' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'generation' => 'integer',
            'dispatch_attempts' => 'integer',
            'attempt' => 'integer',
            'orchestration_attempt' => 'integer',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** Work-run control-plane timestamps are stored and interpreted as UTC. */
    protected function asDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestamp((int) $value, 'UTC');
        }

        if (is_string($value)) {
            $date = CarbonImmutable::createFromFormat($this->getDateFormat(), $value, 'UTC');
            if ($date !== false) {
                return $date;
            }
        }

        return CarbonImmutable::parse($value, 'UTC');
    }
}

<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class WallObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['historical_outcome_eligible' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Wall observations are append-only. Record a new observation.'));
        static::deleting(fn () => throw new DomainException('Wall observations require an explicit archival retention operation.'));
    }
}

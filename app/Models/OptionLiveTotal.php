<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptionLiveTotal extends Model
{
    protected $fillable = [
        'symbol',
        'trade_date',
        'call_volume',
        'put_volume',
        'volume',
        'premium_usd',
        'asof',
        'source_updated_at',
        'source_row_id',
        'freshness_key',
    ];

    protected $casts = [
        'trade_date' => 'immutable_date:Y-m-d',
        'call_volume' => 'integer',
        'put_volume' => 'integer',
        'volume' => 'integer',
        'premium_usd' => 'decimal:4',
        'asof' => 'immutable_datetime',
        'source_updated_at' => 'immutable_datetime',
        'source_row_id' => 'integer',
    ];
}

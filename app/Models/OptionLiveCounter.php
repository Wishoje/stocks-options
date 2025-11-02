<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptionLiveCounter extends Model
{
    protected $table = 'option_live_counters';

    protected $fillable = [
        'symbol',
        'trade_date',
        'exp_date',
        'strike',
        'option_type',
        'volume',
        'premium_usd',
        'asof',
    ];

    protected $casts = [
        'trade_date'  => 'date:Y-m-d',
        'exp_date'    => 'date:Y-m-d',
        'strike'      => 'decimal:4',
        'volume'      => 'integer',
        'premium_usd' => 'decimal:2',
        'asof'        => 'datetime',
    ];
}

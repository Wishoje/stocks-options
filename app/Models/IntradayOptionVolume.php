<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntradayOptionVolume extends Model
{
    protected $table = 'intraday_option_volumes';

    protected $fillable = [
        'symbol',
        'contract_symbol',
        'contract_type',
        'expiration_date',
        'strike_price',
        'volume',
        'open_interest',
        'implied_volatility',
        'delta',
        'gamma',
        'theta',
        'vega',
        'last_price',
        'change',
        'change_percent',
        'request_id',
        'captured_at',
    ];

    protected $casts = [
        'expiration_date' => 'date:Y-m-d',
        'captured_at'     => 'datetime',
        'strike_price'    => 'decimal:4',
        'volume'          => 'integer',
        'open_interest'   => 'integer',
        'implied_volatility' => 'decimal:6',
        'delta'           => 'decimal:10',
        'gamma'           => 'decimal:10',
        'theta'           => 'decimal:10',
        'vega'            => 'decimal:10',
        'last_price'      => 'decimal:6',
        'change'          => 'decimal:6',
        'change_percent'  => 'decimal:6',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SymbolWallSnapshot extends Model
{
    protected $fillable = [
        'symbol',
        'trade_date',
        'timeframe',
        'spot',
        'eod_put_wall',
        'eod_call_wall',
        'eod_put_dist_pct',
        'eod_call_dist_pct',
        'intraday_put_wall',
        'intraday_call_wall',
        'intraday_put_dist_pct',
        'intraday_call_dist_pct',
    ];
}

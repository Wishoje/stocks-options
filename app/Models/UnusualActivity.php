<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnusualActivity extends Model
{
    protected $table = 'unusual_activity';

    protected $fillable = [
        'symbol','data_date','exp_date','strike','z_score','vol_oi','meta'
    ];

    protected $casts = [
        'data_date' => 'date',
        'exp_date'  => 'date',
        'strike'    => 'float',
        'z_score'   => 'float',
        'vol_oi'    => 'float',
        'meta'      => 'array',
    ];
}

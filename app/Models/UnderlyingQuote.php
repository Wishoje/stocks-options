<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnderlyingQuote extends Model
{
    protected $table = 'underlying_quotes';

    protected $fillable = [
        'symbol',
        'source',
        'last_price',
        'prev_close',
        'asof',
    ];

    protected $casts = [
        'last_price' => 'float',
        'prev_close' => 'float',
        'asof'       => 'datetime',
    ];
}

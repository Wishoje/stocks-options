<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OptionChainData extends Model
{
    use HasFactory;

    protected $table = 'option_chain_data';

    protected $fillable = [
        'expiration_id',
        'data_date',
        'option_type',
        'strike',
        'open_interest',
        'volume',
        'gamma',
        'delta',
        'iv',
        'underlying_price',
    ];

    public function expiration()
    {
        return $this->belongsTo(OptionExpiration::class, 'expiration_id');
    }
}

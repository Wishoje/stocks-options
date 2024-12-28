<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OptionExpiration extends Model
{
    use HasFactory;

    protected $table = 'option_expirations';

    // fields we allow mass assignment
    protected $fillable = [
        'symbol',
        'expiration_date',
    ];

    public function chainData()
    {
        return $this->hasMany(OptionChainData::class, 'expiration_id');
    }
}

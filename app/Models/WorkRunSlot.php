<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkRunSlot extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'kind',
        'provider',
        'symbol',
        'parameters',
        'generation',
        'current_run_id',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'generation' => 'integer',
        ];
    }
}

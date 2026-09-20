<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paused' => 'boolean'];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['second_symbol' => 'QQQ', 'paused' => false]);
    }
}

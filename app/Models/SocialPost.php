<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialPost extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['image_base64'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'approved_at' => 'datetime', 'published_at' => 'datetime'];
    }
}

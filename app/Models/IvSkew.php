<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class IvSkew extends Model {
    protected $table = 'iv_skew';
    protected $fillable = [
        'symbol','data_date','exp_date',
        'iv_put_25d','iv_call_25d','skew_pc','curvature',
        'skew_pc_dod','curvature_dod'
    ];
}

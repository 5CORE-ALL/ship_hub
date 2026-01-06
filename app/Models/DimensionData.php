<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DimensionData extends Model
{
    use HasFactory;

    protected $table = 'dimension_data';

    protected $fillable = [
        'sku',
        'parent',
        'product_master_id',
        'wt_act',
        'wt_decl',
        'l',
        'w',
        'h',
        'cbm',
        'ctn_l',
        'ctn_w',
        'ctn_h',
        'ctn_cbm',
        'ctn_qty',
        'ctn_cbm_each',
        'cbm_e',
        'ctn_gwt',
    ];

    protected $casts = [
        'wt_act' => 'decimal:2',
        'wt_decl' => 'decimal:2',
        'l' => 'decimal:2',
        'w' => 'decimal:2',
        'h' => 'decimal:2',
        'cbm' => 'decimal:6',
        'ctn_l' => 'decimal:2',
        'ctn_w' => 'decimal:2',
        'ctn_h' => 'decimal:2',
        'ctn_cbm' => 'decimal:6',
        'ctn_qty' => 'decimal:2',
        'ctn_cbm_each' => 'decimal:6',
        'cbm_e' => 'decimal:2',
        'ctn_gwt' => 'decimal:2',
    ];
}

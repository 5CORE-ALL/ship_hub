<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostMaster extends Model
{
    protected $table = 'product_master'; // Your table name

    public $timestamps = false; 

    // Fillable fields for mass assignment
    protected $fillable = [
        'id',
        'parent',
        'sku',
        'fb',
        'Values'
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifySku extends Model
{
    use HasFactory;

    protected $connection = 'invent';
    protected $table = 'shopify_skus';
    
    protected $fillable = [
        'variant_id',
        'sku',
        'inv',
        'quantity',
        'price',
        'price_updated_manually_at',
        'image_src',
        'shopify_l30',
        'available_to_sell',
        'committed',        
        'on_hand',
    ];

    protected $casts = [
        'inv' => 'decimal:2',
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'shopify_l30' => 'decimal:2',
        'available_to_sell' => 'decimal:2',
        'committed' => 'decimal:2',
        'on_hand' => 'decimal:2',
    ];
}

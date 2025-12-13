<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'order_number',
        'order_item_id',
        'sku',
        'asin',
        'upc',
        'product_name',
        'quantity_ordered',
        'quantity_shipped',
        'unit_price',
        'item_tax',
        'promotion_discount',
        'currency',
        'is_gift',
        'weight',
        'weight_unit',
        'dimensions',
        'marketplace',
        'raw_data',
        'height',
        'width',
        'length'
    ];

    protected $casts = [
        'is_gift' => 'boolean',
        'raw_data' => 'array',
    ];

    /**
     * Relationship: belongs to an order
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function cost()
    {
        return $this->hasOne(CostMaster::class, 'sku', 'sku'); 
    }
}
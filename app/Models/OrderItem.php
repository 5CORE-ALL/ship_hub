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
        'length',
        'original_weight',
        'original_length',
        'original_width',
        'original_height'
    ];

    protected $casts = [
        'is_gift' => 'boolean',
        'raw_data' => 'array',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // When creating a new order item, set original values if not already set
        static::creating(function ($orderItem) {
            if (is_null($orderItem->original_weight) && !is_null($orderItem->weight)) {
                $orderItem->original_weight = $orderItem->weight;
            }
            if (is_null($orderItem->original_length) && !is_null($orderItem->length)) {
                $orderItem->original_length = $orderItem->length;
            }
            if (is_null($orderItem->original_width) && !is_null($orderItem->width)) {
                $orderItem->original_width = $orderItem->width;
            }
            if (is_null($orderItem->original_height) && !is_null($orderItem->height)) {
                $orderItem->original_height = $orderItem->height;
            }
        });

        // When updating an order item, preserve original values if they're null
        static::updating(function ($orderItem) {
            // Only set original values if they're null and current values exist
            if (is_null($orderItem->getOriginal('original_weight')) && !is_null($orderItem->weight)) {
                $orderItem->original_weight = $orderItem->getOriginal('weight') ?? $orderItem->weight;
            }
            if (is_null($orderItem->getOriginal('original_length')) && !is_null($orderItem->length)) {
                $orderItem->original_length = $orderItem->getOriginal('length') ?? $orderItem->length;
            }
            if (is_null($orderItem->getOriginal('original_width')) && !is_null($orderItem->width)) {
                $orderItem->original_width = $orderItem->getOriginal('width') ?? $orderItem->width;
            }
            if (is_null($orderItem->getOriginal('original_height')) && !is_null($orderItem->height)) {
                $orderItem->original_height = $orderItem->getOriginal('height') ?? $orderItem->height;
            }
        });
    }

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
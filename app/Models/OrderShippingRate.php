<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderShippingRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'rate_id',
        'rate_type',
        'source',
        'carrier',
        'service',
        'price',
        'currency',
        'is_cheapest',
        'is_gpt_suggestion',
        'raw_data',
    ];

    protected $casts = [
        'is_cheapest' => 'boolean',
        'is_gpt_suggestion' => 'boolean',
        'raw_data' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

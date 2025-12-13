<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingService extends Model
{
    use HasFactory;
    protected $table = 'shipping_service';

    protected $fillable = [
        'carrier_name',
        'service_code',
        'display_name',
        'category',
        'one_rate',
        'active',
    ];

 
    protected $casts = [
        'one_rate' => 'boolean',
        'active'   => 'boolean',
    ];
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
    public function scopeForCarrier($query, $carrier)
    {
        return $query->where('carrier_name', $carrier);
    }
}

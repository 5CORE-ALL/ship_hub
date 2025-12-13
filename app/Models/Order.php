<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'marketplace',
        'marketplace_order_id',
        'store_id',
        'order_number',
        'order_date',
        'order_age',
        'notes',
        'is_gift',
        'item_sku',
        'item_name',
        'batch',
        'quantity',
        'order_total',
        'recipient_name',
        'recipient_company',
        'recipient_email',
        'recipient_phone',
        'ship_address1',
        'ship_address2',
        'ship_city',
        'ship_state',
        'ship_postal_code',
        'ship_country',
        'shipping_carrier',
        'shipping_service',
        'shipping_cost',
        'tracking_number',
        'ship_date',
        'label_status',
        'order_status',
        'payment_status',
        'fulfillment_status',
        'external_order_id',
        'raw_data',
        'shipper_name',
        'shipper_phone',
        'shipper_company',
        'shipper_street',
        'shipper_city',
        'shipper_state',
        'shipper_country',
        'shipper_postal',
        'label_id',
        'label_source',
        'is_address_verified',
        'printing_status',
        'source_name',
        'cancel_status',
        'is_manual',
        'shipping_rate_fetched',
        'queue','print_count','marked_as_ship','dispatch_status','dispatch_date','dispatch_by','shipping_provider_id'
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'ship_date'  => 'datetime',
        'raw_data'   => 'array',
        'is_gift'    => 'boolean',
        'shipping_cost' => 'decimal:2',
        'order_total'   => 'decimal:2',
    ];
    public function shipment()
    {
        return $this->hasOne(\App\Models\Shipment::class, 'order_id');
    }
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }
    public function cost()
    {
        return $this->hasOne(CostMaster::class, 'sku', 'item_sku');
    }
    public function shippingRates()
    {
        return $this->hasMany(OrderShippingRate::class, 'order_id');
    }

    public function cheapestRate()
    {
        return $this->hasOne(OrderShippingRate::class, 'order_id')->where('is_cheapest', 1);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarriersList extends Model
{
    protected $table = 'carriers_list';

    protected $primaryKey = 'carrier_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'carrier_id',
        'carrier_code',
        'account_number',
        'requires_funded_amount',
        'balance',
        'nickname',
        'friendly_name',
        'primary_carrier',
        'has_multi_package_supporting_services',
        'allows_returns',
        'supports_label_messages',
        'disabled_by_billing_plan',
        'funding_source_id',
    ];

    protected $casts = [
        'requires_funded_amount' => 'boolean',
        'balance' => 'decimal:4',
        'primary_carrier' => 'boolean',
        'has_multi_package_supporting_services' => 'boolean',
        'allows_returns' => 'boolean',
        'supports_label_messages' => 'boolean',
        'disabled_by_billing_plan' => 'boolean',
    ];

    protected $dates = ['created_at', 'updated_at'];
}
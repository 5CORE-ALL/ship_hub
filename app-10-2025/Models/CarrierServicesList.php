<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrierServicesList extends Model
{
    protected $table = 'carrier_services_list';

    protected $primaryKey = 'id';

    protected $fillable = [
        'carrier_id',
        'service_code',
        'name',
        'domestic',
        'international',
        'is_multi_package_supported',
        'is_return_supported',
        'display_schemes',
    ];

    protected $casts = [
        'domestic' => 'boolean',
        'international' => 'boolean',
        'is_multi_package_supported' => 'boolean',
        'is_return_supported' => 'boolean',
        'display_schemes' => 'json',
    ];

    public function carrier()
    {
        return $this->belongsTo(Carrier::class, 'carrier_id', 'carrier_id');
    }
}
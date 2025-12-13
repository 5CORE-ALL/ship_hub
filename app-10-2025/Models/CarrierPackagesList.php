<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrierPackagesList extends Model
{
    protected $table = 'carrier_packages_list';

    protected $primaryKey = 'id';

    protected $fillable = [
        'carrier_id',
        'package_code',
        'name',
        'description',
    ];

    public function carrier()
    {
        return $this->belongsTo(Carrier::class, 'carrier_id', 'carrier_id');
    }
}
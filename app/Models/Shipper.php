<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shipper extends Model
{
    use HasFactory;
    protected $table = 'shippers';
    protected $fillable = [
        'name',
        'shipper_number',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
    ];
}

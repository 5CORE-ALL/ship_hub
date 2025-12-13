<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'platform',
        'store_url',
        'app_id',
        'app_secret',
        'redirect_uri',
        'status',
    ];
}

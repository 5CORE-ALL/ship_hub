<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CarrierAccount extends Model
{
    use HasFactory;

    protected $table = 'carrier_accounts';
    protected $fillable = [
        'user_id',
        'carrier_name',
        'account_number',
        'client_id',
        'client_secret',
        'meter_number',
        'api_environment'
    ];
    public $timestamps = true;
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

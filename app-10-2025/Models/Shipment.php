<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'carrier',
        'service_type',
        'package_weight',
        'package_dimensions',
        'tracking_number',
        'label_url',
        'shipment_status',
        'label_data',
        'ship_date',
        'cost',
        'currency',
        'tracking_url',
        'void_status',
        'label_id',
        'label_status',
        'created_by',
        'cancelled_by' 
    ];

    protected $casts = [
        'ship_date' => 'date',
        'label_data' => 'array',
    ];
    protected $attributes = [
        'currency' => 'USD',
    ];
    public function shipments()
    {
        return $this->hasMany(\App\Models\Shipment::class, 'order_id');
    }
    public function getLabelUrlWithCredsAttribute()
    {
        if (strtolower($this->carrier) === 'sendle' && $this->label_url) {
            $apiKey = env('SENDLE_KEY');    
            $apiSecret = env('SENDLE_SECRET');

            $parsedUrl = parse_url($this->label_url);

            $urlWithCred = $parsedUrl['scheme'] . '://' . $apiKey . ':' . $apiSecret . '@' 
                           . $parsedUrl['host'] . $parsedUrl['path'];

            if (isset($parsedUrl['query'])) {
                $urlWithCred .= '?' . $parsedUrl['query'];
            }

            return $urlWithCred;
        }

        return $this->label_url;
    }

}

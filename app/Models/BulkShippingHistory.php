<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BulkShippingHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_ids',
        'providers',
        'merged_pdf_url',
        'status',
        'processed',
        'success',
        'failed',
        'success_order_ids',
        'failed_order_ids',
        'mail_sent'
    ];

    protected $casts = [
        'order_ids'         => 'array', 
        'providers'         => 'array',
        'success_order_ids' => 'array',   
        'failed_order_ids'  => 'array',  
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

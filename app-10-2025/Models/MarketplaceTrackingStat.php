<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketplaceTrackingStat extends Model
{
    use HasFactory;

    protected $table = 'marketplace_tracking_stats';

    protected $fillable = [
        'marketplace_name',
        'total_orders',
        'valid_tracking',
        'invalid_tracking',
        'valid_tracking_rate',
        'allowed_rate',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'total_orders' => 'integer',
        'valid_tracking' => 'integer',
        'invalid_tracking' => 'integer',
        'valid_tracking_rate' => 'decimal:2',
        'allowed_rate' => 'decimal:2',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the store ID (assuming a relationship if needed; adjust as per your schema).
     *
     * @return int|null
     */
    public function getStoreIdAttribute()
    {
        // If you have a store_id column or relationship, implement here.
        // For now, assuming it's not directly in this table.
        return null;
    }
}
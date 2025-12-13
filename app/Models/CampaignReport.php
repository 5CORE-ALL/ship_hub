<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignReport extends Model
{
    protected $table = 'campaign_reports';

    protected $fillable = [
        'marketplace',
        'profile_id',
        'campaign_id',
        'campaign_name',
        'ad_type',
        'report_date_range',
        'start_date',
        'end_date',
        'impressions',
        'clicks',
        'cost',
        'spend',
        'sales',
        'orders',
        'cost_per_click',
        'campaign_status',
        'campaign_budget_amount',
        'campaign_budget_currency_code'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'cost' => 'decimal:4',
        'spend' => 'decimal:4',
        'sales' => 'decimal:4',
        'orders' => 'integer',
        'cost_per_click' => 'decimal:4',
        'campaign_budget_amount' => 'decimal:4',
    ];
}

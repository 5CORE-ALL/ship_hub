<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmazonReport extends Model
{
    use HasFactory;

    protected $table = 'amazon_reports';

    protected $fillable = [
        'report_id',
        'ad_product',
        'report_type',
        'status',
        'start_date',
        'end_date',
        'download_url',
        'is_processed',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_processed' => 'boolean',
    ];
}

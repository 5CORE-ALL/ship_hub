<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyOverdueCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'record_date',
        'overdue_count',
    ];

    protected $casts = [
        'record_date' => 'date',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommandLog extends Model
{
    protected $fillable = [
        'command',
        'log_file',
        'started_at',
        'completed_at',
        'status',
        'duration',
        'response',
        'error_message'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getCreatedAtAttribute($value)
    {
        return strtotime($value) ? Date('d-M-Y', strtotime($value)) : null;
    }

    public function getStartedAtAttribute($value)
    {
        return strtotime($value) ? Date('h:i:s A', strtotime($value)) : null;
    }

    public function getCompletedAtAttribute($value)
    {
        return strtotime($value) ? Date('h:i:s A', strtotime($value)) : null;
    }

    public function getDurationAttribute($value)
    {
        return round($value / 60, 2);
    }
}

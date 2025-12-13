<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransactionLog extends Model
{
    use SoftDeletes;

    protected $table = 'transaction_logs';

    protected $fillable = [
        'transaction_type',
        'status',
        'reference_id',
        'payload',
        'response',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];
}

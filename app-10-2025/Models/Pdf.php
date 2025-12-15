<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pdf extends Model
{
    use HasFactory;

    protected $table = 'pdfs';

    protected $fillable = [
        'label_url',
        'original_name',
        'created_by',
        'upload_date',
    ];

    protected $casts = [
        'upload_date' => 'datetime',  
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

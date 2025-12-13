<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserColumnVisibility extends Model
{
    use HasFactory;
    protected $table = 'user_column_visibility';

    protected $fillable = [
        'user_id',
        'screen_name',
        'column_name',
        'is_visible',
        'order_index',
        'width',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function scopeForScreen($query, $screenName)
    {
        return $query->where('screen_name', $screenName);
    }
    public function scopeForUser($query, $userId = null)
    {
        return $query->where('user_id', $userId ?? auth()->id());
    }
}

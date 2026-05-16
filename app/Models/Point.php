<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Point extends Model
{
     protected $fillable = [
        'user_id',
        'order_id',
        'points',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

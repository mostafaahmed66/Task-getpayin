<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['hold_id', 'status', 'total'];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }
}

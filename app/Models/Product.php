<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'price', 'stock'];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    public function holds()
    {
        return $this->hasMany(Hold::class);
    }

    public function getAvailableStockAttribute()
    {
        $activeHolds = $this->holds()
            ->where('expires_at', '>', now())
            ->doesntHave('order')
            ->sum('qty');

       
        return $this->stock - $activeHolds;
    }
}

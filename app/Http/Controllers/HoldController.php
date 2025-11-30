<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HoldController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1',
        ]);

        $productId = $request->product_id;
        $qty = $request->qty;
        $cacheKey = "product_stock_{$productId}";

        $stock = Cache::remember($cacheKey, 300, function () use ($productId) {
            return Product::find($productId)->available_stock;
        });

        if ($qty > $stock) {
            return response()->json(['error' => 'Insufficient stock'], 409);
        }

        return DB::transaction(function () use ($productId, $qty, $cacheKey) {
            $product = Product::lockForUpdate()->find($productId);

            
            if ($qty > $product->available_stock) {
                return response()->json(['error' => 'Insufficient stock'], 409);
            }

            $remaining = Cache::decrement($cacheKey, $qty);
            if ($remaining < 0) {
                Cache::increment($cacheKey, $qty);
                return response()->json(['error' => 'Insufficient stock'], 409);
            }

            $hold = Hold::create([
                'product_id' => $product->id,
                'qty' => $qty,
                'expires_at' => now()->addMinutes(2),
                'token' => Str::uuid(),
            ]);

            return response()->json([
                'hold_id' => $hold->id,
                'expires_at' => $hold->expires_at,
                'token' => $hold->token,
            ], 201);
        });
    }

   public function releaseExpiredHold(Hold $hold)
{
    $cacheKey = "product_stock_{$hold->product_id}";

    if ($hold->expires_at->isPast() && !$hold->order) {
        Cache::increment($cacheKey, $hold->qty);
        $hold->delete();
    }
}

}

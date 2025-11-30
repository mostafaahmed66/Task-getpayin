<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Order;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{
  
public function store(Request $request)
{
    $request->validate([
        'hold_id' => 'required|exists:holds,id',
    ]);

    $holdId = $request->hold_id;

    $lock = Cache::lock("hold_order_{$holdId}", 10);

    if (!$lock->get()) {
        return response()->json(['error' => 'Hold is being processed, try again'], 429);
    }

    try {
        $order = DB::transaction(function () use ($holdId) {
            $hold = Hold::lockForUpdate()->find($holdId);

            if ($hold->expires_at < now()) {
                return response()->json(['error' => 'Hold expired'], 400);
            }

            if ($hold->order) {
                return response()->json(['error' => 'Order already created for this hold'], 409);
            }

            $order = Order::create([
                'hold_id' => $hold->id,
                'status' => 'pending',
                'total' => $hold->product->price * $hold->qty,
            ]);

            $hold->product->decrement('stock', $hold->qty);

            return $order;
        });

    } finally {
        $lock->release();
    }

    return response()->json(['order_id' => $order->id, 'status' => $order->status], 201);
}

}

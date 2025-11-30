<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Order;
use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache; 

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $key = $request->header('Idempotency-Key');
        if (!$key) {
            return response()->json(['error' => 'Missing Idempotency-Key'], 400);
        }

        if (Cache::has("idempotency_{$key}")) {
        return response()->json(Cache::get("idempotency_{$key}")['body'], Cache::get("idempotency_{$key}")['code']);
}

        $orderId = $request->input('order_id');
        $status = $request->input('status'); 

        if (!$orderId || !$status) {
             return response()->json(['error' => 'Missing order_id or status'], 400);
        }

        $response = DB::transaction(function () use ($orderId, $status) {
            $order = Order::lockForUpdate()->find($orderId);
            if (!$order) {
                return ['body' => ['error' => 'Order not found'], 'code' => 404];
            }

            if ($order->status !== 'pending') {
                 return ['body' => ['message' => 'Order already processed', 'status' => $order->status], 'code' => 200];
            }

            if ($status === 'success') {
                $order->update(['status' => 'paid']);
        
                $order->hold->update(['expires_at' => now()]);
            } else {
                $order->update(['status' => 'cancelled']);
                
                $order->hold->product->increment('stock', $order->hold->qty);
                $cacheKey = "product_stock_{$order->hold->product_id}";
                Cache::increment($cacheKey, $order->hold->qty);
                $order->hold->update(['expires_at' => now()]);
            }

            return ['body' => ['status' => $order->status], 'code' => 200];
        });

        IdempotencyKey::create([
            'key' => $key,
            'response_body' => $response['body'],
            'status_code' => $response['code'],
        ]);

        Cache::put("idempotency_{$key}", $response, now()->addDay());

        return response()->json($response['body'], $response['code']);
    }
}

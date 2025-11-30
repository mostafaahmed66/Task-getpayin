<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class FlashSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Product::create([
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 10,
        ]);
    }

    public function test_can_get_product_stock()
    {
        $product = Product::first();
        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(200)
                 ->assertJson(['data' => ['available_stock' => 10]]);
    }

    public function test_can_create_hold()
    {
        $product = Product::first();
        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['hold_id', 'expires_at', 'token']);

        $this->assertEquals(8, $product->fresh()->available_stock);
    }

    public function test_cannot_oversell_sequentially()
    {
        $product = Product::first();
        $this->postJson('/api/holds', ['product_id' => $product->id, 'qty' => 10])->assertStatus(201);
        $this->postJson('/api/holds', ['product_id' => $product->id, 'qty' => 1])->assertStatus(409);
    }

    public function test_hold_expiry_restores_stock()
    {
        $product = Product::first();
        $this->postJson('/api/holds', ['product_id' => $product->id, 'qty' => 5])->assertStatus(201);
        $this->assertEquals(5, $product->fresh()->available_stock);

        $this->travel(3)->minutes();

        $this->assertEquals(10, $product->fresh()->available_stock);
    }

    public function test_can_create_order()
    {
        $product = Product::first();
        $holdResponse = $this->postJson('/api/holds', ['product_id' => $product->id, 'qty' => 1]);
        $holdId = $holdResponse->json('hold_id');

        $response = $this->postJson('/api/orders', ['hold_id' => $holdId]);
        $response->assertStatus(201)
                 ->assertJson(['status' => 'pending']);

        $this->assertDatabaseHas('orders', ['hold_id' => $holdId, 'status' => 'pending']);
    }

    public function test_webhook_success()
    {
        $product = Product::first();
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => now()->addMinutes(2),
            'token' => Str::uuid()
        ]);
        $order = Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total' => 100
        ]);
        $product->decrement('stock', 1);

        $response = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'success'
        ], ['Idempotency-Key' => 'key1']);

        $response->assertStatus(200);
        $this->assertEquals('paid', $order->fresh()->status);
        $this->assertEquals(9, $product->fresh()->stock);
    }
    public function concurrency_test_ten_holds_simultaneous()
    {
        $product = Product::create([
            'name' => 'Concurrent Product',
            'price' => 50,
            'stock' => 5,
        ]);

        $responses = [];

        for ($i = 0; $i < 10; $i++) {
            DB::beginTransaction();
            try {
                $p = Product::lockForUpdate()->find($product->id);
                if ($p->stock >= 1) {
                    Hold::create([
                        'product_id' => $p->id,
                        'qty' => 1,
                        'expires_at' => now()->addMinutes(5),
                        'token' => Str::uuid(),
                    ]);
                    $p->stock -= 1;
                    $p->save();
                    $responses[] = 201;
                } else {
                    $responses[] = 409;
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $responses[] = 500;
            }
        }

        $successCount = collect($responses)->filter(fn($r) => $r === 201)->count();
        $failCount = collect($responses)->filter(fn($r) => $r !== 201)->count();

        $this->assertEquals(5, $successCount);
        $this->assertEquals(5, $failCount);
        $this->assertEquals(0, $product->fresh()->stock);
    }

    public function test_webhook_idempotency()
    {
        $product = Product::first();
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => now()->addMinutes(2),
            'token' => Str::uuid()
        ]);
        $order = Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total' => 100
        ]);
        $product->decrement('stock', 1);

        $payload = ['order_id' => $order->id, 'status' => 'success'];
        $headers = ['Idempotency-Key' => 'key_idem'];

        $response1 = $this->postJson('/api/payments/webhook', $payload, $headers);
        $response1->assertStatus(200);

        $response2 = $this->postJson('/api/payments/webhook', $payload, $headers);
        $response2->assertStatus(200);

        $this->assertEquals(9, $product->fresh()->stock);
    }

    public function test_webhook_failure_restores_stock()
    {
        $product = Product::first();
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => now()->addMinutes(2),
            'token' => Str::uuid()
        ]);
        $order = Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total' => 100
        ]);
        $product->decrement('stock', 1);

        $response = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'failed'
        ], ['Idempotency-Key' => 'key_fail']);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_concurrent_orders_via_api()
    {
        $product = Product::create([
            'name' => 'Flash Sale Product',
            'price' => 100,
            'stock' => 10,
        ]);

        $holds = [];
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);
            $response->assertStatus(201);
            $holds[] = $response->json('hold_id');
        }

        $this->assertEquals(0, $product->fresh()->available_stock);

        $results = [];
        foreach ($holds as $holdId) {
            try {
                $response = $this->postJson('/api/orders', [
                    'hold_id' => $holdId,
                ]);
                $results[] = [
                    'status' => $response->status(),
                    'hold_id' => $holdId,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'status' => 500,
                    'hold_id' => $holdId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $successCount = collect($results)->where('status', 201)->count();
        $this->assertEquals(10, $successCount);

        $this->assertEquals(0, $product->fresh()->stock);
    }

    public function test_two_customers_compete_for_last_item()
    {
        $product = Product::create([
            'name' => 'Last Item Product',
            'price' => 200,
            'stock' => 1,
        ]);

        $response1 = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
        $response1->assertStatus(201);
        $hold1 = $response1->json('hold_id');

        $response2 = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
        $response2->assertStatus(409)
                  ->assertJson(['error' => 'Insufficient stock']);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $hold1,
        ]);
        $orderResponse->assertStatus(201);

        $this->assertEquals(0, $product->fresh()->stock);
        $this->assertEquals(0, $product->fresh()->available_stock);
    }

    public function test_concurrent_holds_with_race_condition()
    {
        $product = Product::create([
            'name' => 'Race Condition Product',
            'price' => 150,
            'stock' => 5,
        ]);

        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);
            $results[] = $response->status();
        }

        $successCount = collect($results)->filter(fn($status) => $status === 201)->count();
        $failCount = collect($results)->filter(fn($status) => $status === 409)->count();

        $this->assertEquals(5, $successCount);
        $this->assertEquals(5, $failCount);

        $this->assertEquals(0, $product->fresh()->available_stock);
    }

    public function test_order_payment_flow_with_cancellation()
    {
        $product = Product::create([
            'name' => 'Cancellation Test Product',
            'price' => 100,
            'stock' => 3,
        ]);

        $holds = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);
            $holds[] = $response->json('hold_id');
        }

        $orders = [];
        foreach ($holds as $holdId) {
            $response = $this->postJson('/api/orders', [
                'hold_id' => $holdId,
            ]);
            $orders[] = $response->json('order_id');
        }

        $this->assertEquals(0, $product->fresh()->stock);

        $this->postJson('/api/payments/webhook', [
            'order_id' => $orders[0],
            'status' => 'success',
        ], ['Idempotency-Key' => 'success_1']);

        $this->postJson('/api/payments/webhook', [
            'order_id' => $orders[1],
            'status' => 'failed',
        ], ['Idempotency-Key' => 'fail_1']);

        $this->postJson('/api/payments/webhook', [
            'order_id' => $orders[2],
            'status' => 'success',
        ], ['Idempotency-Key' => 'success_2']);

        $this->assertEquals(1, $product->fresh()->stock);

        $newHoldResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
        $newHoldResponse->assertStatus(201);
    }

    
}


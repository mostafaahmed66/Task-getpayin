<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class Testt extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function can_create_hold_successfully()
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 10,
        ]);

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $this->assertEquals(8, $product->fresh()->stock);
    }

    #[Test]
    public function cannot_create_hold_if_stock_not_enough()
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 1,
        ]);

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);

        $response->assertStatus(400)
                 ->assertJson(['error' => 'Not enough stock']);
    }

    #[Test]
    public function cannot_create_hold_with_invalid_product()
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => 999,
            'qty' => 1,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function can_create_order_from_hold()
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 10,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 2,
            'expires_at' => now()->addMinutes(5),
            'token' => Str::uuid(),
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['order_id', 'status']);

        $this->assertDatabaseHas('orders', [
            'hold_id' => $hold->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function cannot_create_order_if_hold_expired()
    {
        $hold = Hold::create([
            'product_id' => 1,
            'qty' => 1,
            'expires_at' => now()->subMinutes(1),
            'token' => Str::uuid(),
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(400)
                 ->assertJson(['error' => 'Hold expired']);
    }

    #[Test]
    public function cannot_create_duplicate_order_for_same_hold()
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 10,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => now()->addMinutes(5),
            'token' => Str::uuid(),
        ]);

        Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total' => $product->price,
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(409)
                 ->assertJson(['error' => 'Order already created for this hold']);
    }

    #[Test]
    public function cannot_create_order_with_invalid_hold()
    {
        $response = $this->postJson('/api/orders', [
            'hold_id' => 999,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function concurrency_test_ten_holds_simultaneous()
    {
        $product = Product::create([
            'name' => 'Concurrent Product',
            'price' => 50,
            'stock' => 5,
        ]);

        $responses = [];

        // Simulate concurrency with transactions and lockForUpdate
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
}

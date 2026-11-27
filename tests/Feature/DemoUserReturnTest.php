<?php

namespace Tests\Feature;

use App\Enums\PayoutMode;
use App\Models\Order;
use App\Models\Training;
use App\Models\User;
use App\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demo-only behaviour: the demo user lives in localStorage, shared by every
 * tab, so it can change while a buyer is away at the gateway.
 */
class DemoUserReturnTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $buyer = User::where('name', 'like', 'Aisyah%')->firstOrFail();
        $training = Training::where('title', 'like', 'Laravel for Beginners%')->firstOrFail();
        $this->order = app(OrderService::class)->create($buyer, $training, null, 0, 'fake', PayoutMode::Deferred)['order'];
    }

    public function test_payment_return_switches_back_to_the_buyer(): void
    {
        $payment = $this->order->payments()->firstOrFail();

        $this->get(route('payment.return', ['payment' => $payment->id]))
            ->assertRedirect("/orders/{$this->order->id}?returned=1&as={$this->order->buyer_id}");
    }

    public function test_forbidden_order_names_its_owner(): void
    {
        $other = User::where('name', 'like', 'Akademi Digital%')->firstOrFail();

        $this->withHeader('X-Demo-User', (string) $other->id)->getJson("/api/orders/{$this->order->id}")
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'This order belongs to Aisyah (individual).',
                'owner_id' => $this->order->buyer_id,
            ]);
    }
}

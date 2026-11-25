<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Training;
use App\Models\User;
use App\Payments\Gateways\FakeGateway;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_only_the_admin_can_open_the_dashboard(): void
    {
        $buyer = User::where('role', 'individual')->firstOrFail();

        $this->withHeader('X-Demo-User', (string) $buyer->id)->getJson('/api/platform/dashboard')->assertForbidden();
    }

    public function test_it_returns_one_row_per_order_in_the_period_and_the_attention_counts(): void
    {
        $this->paidOrder();
        $this->unpaidOrder();
        $this->travelTo(now()->addDay());

        $response = $this->asAdmin()->getJson('/api/platform/dashboard?days=7')
            ->assertOk()
            ->assertJsonPath('range.days', 7)
            ->assertJsonStructure([
                'range' => ['days', 'from', 'to'],
                'orders' => [['id', 'reference', 'at', 'day', 'status', 'gateway', 'training', 'seller', 'buyer', 'paid', 'amount_sen', 'refunded_sen', 'commission_sen']],
                'attention' => ['refund_requests', 'refunds_owed', 'refunds_owed_sen', 'clawbacks', 'open_disputes', 'held_sen', 'books_balanced'],
            ])
            ->assertJsonPath('attention.books_balanced', true);

        $this->assertCount(2, $response->json('orders'));

        $paid = Order::whereNotNull('paid_at')->sum('amount_sen');
        $this->assertSame((int) $paid, collect($response->json('orders'))->sum('amount_sen'), 'unpaid orders count as RM0 collected');
    }

    public function test_orders_outside_the_period_are_left_out_and_unknown_ranges_fall_back_to_30_days(): void
    {
        $this->paidOrder();
        $this->travelTo(now()->addDays(40));

        $this->asAdmin()->getJson('/api/platform/dashboard?days=7')->assertOk()->assertJsonCount(0, 'orders');
        $this->asAdmin()->getJson('/api/platform/dashboard?days=12345')->assertOk()->assertJsonPath('range.days', 30);
        $this->asAdmin()->getJson('/api/platform/dashboard?days=90')->assertOk()->assertJsonCount(1, 'orders');
    }

    private function paidOrder(): Order
    {
        $order = $this->unpaidOrder();
        $payment = $order->payments()->first();
        $body = json_encode(['event_id' => "evt_pay_{$payment->id}", 'reference' => $payment->gateway_reference, 'status' => 'paid', 'amount_sen' => $payment->amount_sen]);
        $this->call('POST', '/api/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => app(FakeGateway::class)->sign($body),
        ], $body)->assertJson(['result' => 'paid']);

        return $order->fresh();
    }

    private function unpaidOrder(): Order
    {
        $buyer = User::where('role', 'individual')->firstOrFail();
        $response = $this->withHeader('X-Demo-User', (string) $buyer->id)->postJson('/api/orders', [
            'training_id' => Training::firstOrFail()->id,
            'skillpoints' => 0,
            'gateway' => 'fake',
            'payout_mode' => 'deferred',
        ])->assertCreated();

        return Order::findOrFail($response->json('order.id'));
    }

    private function asAdmin(): static
    {
        return $this->withHeader('X-Demo-User', (string) User::where('role', 'platform')->firstOrFail()->id);
    }
}

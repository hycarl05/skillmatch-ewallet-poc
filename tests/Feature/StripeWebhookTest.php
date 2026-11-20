<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PayoutMode;
use App\Enums\SplitStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Training;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\Payouts\StripeConnectPayoutProvider;
use App\Payments\Gateways\StripeGateway;
use App\Services\Ledger;
use App\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Mockery;
use Stripe\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stripe's webhook handling, signed the way Stripe signs them. No network
 * calls: the order is created directly and the payment given a session id.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config([
            'skillmatch.gateways.stripe.webhook_secret' => self::SECRET,
            'skillmatch.gateways.stripe.secret_key' => 'sk_test_dummy', // client is built but never called
        ]);

        // Never call Stripe from tests: by default the fee "isn't known yet".
        $this->fakeStripeFee(null);
    }

    private function fakeStripeFee(?int $fee): Mockery\MockInterface
    {
        $gateway = Mockery::mock(StripeGateway::class, [new StripeClient('sk_test_dummy')])->makePartial();
        $gateway->shouldReceive('processingFee')->andReturn($fee)->byDefault();
        $this->app->instance(StripeGateway::class, $gateway);

        return $gateway;
    }

    public function test_paid_checkout_session_marks_order_paid_and_holds_shares(): void
    {
        [$order, $payment] = $this->stripeOrder();

        $this->stripeWebhook('evt_1', 'checkout.session.completed', $payment, 'paid')
            ->assertOk()->assertJson(['result' => 'paid']);

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertTrue($order->splits()->whereNotNull('recipient_id')->get()->every(fn ($s) => $s->status === SplitStatus::Held));
    }

    public function test_retried_event_is_applied_once(): void
    {
        [, $payment] = $this->stripeOrder();

        $this->stripeWebhook('evt_dup', 'checkout.session.completed', $payment, 'paid')->assertJson(['result' => 'paid']);
        $this->stripeWebhook('evt_dup', 'checkout.session.completed', $payment, 'paid')->assertJson(['result' => 'duplicate']);
    }

    public function test_fpx_session_waits_for_async_success(): void
    {
        [$order, $payment] = $this->stripeOrder();

        $this->stripeWebhook('evt_a', 'checkout.session.completed', $payment, 'unpaid')->assertJson(['result' => 'pending']);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);

        $this->stripeWebhook('evt_b', 'checkout.session.async_payment_succeeded', $payment, 'paid')->assertJson(['result' => 'paid']);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_expired_session_fails_the_order(): void
    {
        [$order, $payment] = $this->stripeOrder();

        $this->stripeWebhook('evt_x', 'checkout.session.expired', $payment, 'unpaid')->assertJson(['result' => 'failed']);
        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
    }

    public function test_bad_signature_is_rejected(): void
    {
        [, $payment] = $this->stripeOrder();

        $this->stripeWebhook('evt_bad', 'checkout.session.completed', $payment, 'paid', secret: 'whsec_wrong')
            ->assertStatus(401);
    }

    public function test_tampered_amount_is_not_marked_paid(): void
    {
        [$order, $payment] = $this->stripeOrder();

        $this->stripeWebhook('evt_amt', 'checkout.session.completed', $payment, 'paid', amount: 100)
            ->assertJson(['result' => 'amount_mismatch']);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_stripe_fee_is_recorded_once_and_comes_out_of_skillmatch_commission(): void
    {
        $this->fakeStripeFee(1899)->shouldReceive('processingFee')->once()->andReturn(1899);

        [$order, $payment] = $this->stripeOrder();
        $this->stripeWebhook('evt_fee', 'checkout.session.completed', $payment, 'paid')->assertJson(['result' => 'paid']);

        $aisyah = $order->buyer;
        $hafiz = $order->training->seller;
        $admin = User::where('role', 'platform')->firstOrFail();

        // Admin: what was paid, the processing fee, and what SkillMatch keeps (via commission + shares).
        $this->withHeader('X-Demo-User', (string) $admin->id)->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('view_as', 'platform')
            ->assertJsonPath('amount_sen', 45000)
            ->assertJsonPath('processing_fee_sen', 1899)
            ->assertJsonPath('platform_fee_sen', 4500)
            ->assertJsonPath('payments.0.gateway_fee_sen', 1899);

        // Buyer: only what they paid.
        $buyerView = $this->withHeader('X-Demo-User', (string) $aisyah->id)->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('view_as', 'buyer')
            ->assertJsonPath('amount_sen', 45000);
        foreach (['processing_fee_sen', 'platform_fee_sen', 'platform_fee_percent', 'splits', 'my_amount_sen', 'ledger'] as $hidden) {
            $buyerView->assertJsonMissingPath($hidden);
        }
        $this->assertArrayNotHasKey('gateway_fee_sen', $buyerView->json('payments.0'));

        // Trainer: only what they receive.
        $trainerView = $this->withHeader('X-Demo-User', (string) $hafiz->id)->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('view_as', 'trainer')
            ->assertJsonPath('my_amount_sen', 40500);
        foreach (['amount_sen', 'gross_sen', 'discount_sen', 'processing_fee_sen', 'platform_fee_sen', 'splits', 'payments', 'refunds', 'ledger'] as $hidden) {
            $trainerView->assertJsonMissingPath($hidden);
        }

        // The checkout quote doesn't reveal the split either.
        $this->withHeader('X-Demo-User', (string) $aisyah->id)->getJson("/api/trainings/{$order->training_id}/quote")
            ->assertOk()
            ->assertJsonPath('amount_sen', 45000)
            ->assertJsonMissingPath('seller_share_sen')
            ->assertJsonMissingPath('platform_fee_sen');

        $ledger = app(Ledger::class);
        $this->assertSame(1899, $ledger->balance(Ledger::GATEWAY_FEES));

        // Confirm the training. Hafiz has no Connect account, so the transfer is
        // skipped (no network call) and his share waits in "payable", in full.
        $hafiz = $order->training->seller;
        $service = app(OrderService::class);
        $service->complete($order->fresh(), $hafiz);
        $service->confirm($order->fresh(), $aisyah);

        $this->assertSame(40500, $ledger->balance("payable:{$hafiz->id}"), 'trainer share is not reduced by the fee');
        $this->assertSame(4500 - 1899, $ledger->balance(Ledger::PLATFORM_BANK), 'SkillMatch keeps commission minus the fee');
        $this->assertSame((int) LedgerEntry::sum('debit_sen'), (int) LedgerEntry::sum('credit_sen'));
    }

    public function test_stripe_orders_pay_out_through_connect(): void
    {
        $this->assertInstanceOf(StripeConnectPayoutProvider::class, app(GatewayManager::class)->payoutProvider('stripe'));
        $this->assertNotInstanceOf(StripeConnectPayoutProvider::class, app(GatewayManager::class)->payoutProvider('fake'));
    }

    /** @return array{Order, Payment} */
    private function stripeOrder(): array
    {
        // Create through the fake gateway (no network), then make it look like a Stripe checkout.
        $buyer = User::where('name', 'like', 'Aisyah%')->firstOrFail();
        $training = Training::where('title', 'like', 'Laravel for Beginners%')->firstOrFail();
        $order = app(OrderService::class)->create($buyer, $training, null, 0, 'fake', PayoutMode::Deferred)['order'];

        $payment = $order->payments()->firstOrFail();
        $sessionId = 'cs_test_'.$payment->id;
        $order->update(['gateway' => 'stripe']);
        $payment->update(['gateway' => 'stripe', 'gateway_reference' => $sessionId]);

        return [$order->fresh(), $payment->fresh()];
    }

    private function stripeWebhook(string $eventId, string $type, Payment $payment, string $paymentStatus, ?int $amount = null, string $secret = self::SECRET): TestResponse
    {
        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => [
                'id' => $payment->gateway_reference,
                'object' => 'checkout.session',
                'payment_status' => $paymentStatus,
                'amount_total' => $amount ?? $payment->amount_sen,
                'currency' => 'myr',
            ]],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}

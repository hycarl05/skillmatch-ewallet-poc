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
use App\Payments\RefundResult;
use App\Models\Refund;
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

    public function test_payment_intent_is_stored_so_disputes_can_be_matched(): void
    {
        [, $payment] = $this->stripeOrder();
        $this->stripeWebhook('evt_pi', 'checkout.session.completed', $payment, 'paid');

        $this->assertSame('pi_test_'.$payment->id, $payment->fresh()->gateway_payment_id);
    }

    public function test_refund_updated_webhook_marks_a_pending_refund_failed_and_owed_again(): void
    {
        $this->fakeStripeFee(null)->shouldReceive('refund')->andReturn(new RefundResult(RefundResult::PENDING, 're_test_1'));
        [$order, $payment] = $this->stripeOrder();
        $this->stripeWebhook('evt_paid', 'checkout.session.completed', $payment, 'paid');

        $admin = User::where('role', 'platform')->firstOrFail();
        $this->withHeader('X-Demo-User', (string) $admin->id)
            ->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'FPX cancel'])
            ->assertJsonPath('refunds.0.status', 'pending');

        $this->stripeObjectWebhook('evt_rf', 'refund.updated', [
            'id' => 're_test_1', 'object' => 'refund', 'status' => 'failed', 'amount' => 45000, 'failure_reason' => 'expired_or_canceled_card',
        ])->assertJson(['result' => 'refund_failed']);

        $refund = $order->refunds()->sole();
        $this->assertSame(Refund::FAILED, $refund->status);
        $this->assertSame('expired_or_canceled_card', $refund->failure_reason);
        $this->assertSame(45000, app(Ledger::class)->balance(Ledger::REFUNDS_OWED));
        $this->assertSame((int) LedgerEntry::sum('debit_sen'), (int) LedgerEntry::sum('credit_sen'));
    }

    public function test_refund_made_in_the_stripe_dashboard_is_recorded(): void
    {
        [$order, $payment] = $this->stripeOrder();
        $this->stripeWebhook('evt_paid', 'checkout.session.completed', $payment, 'paid');

        $this->stripeObjectWebhook('evt_dash', 'refund.created', [
            'id' => 're_dash_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 45000,
            'payment_intent' => 'pi_test_'.$payment->id, 'reason' => 'requested_by_customer', 'metadata' => [],
        ])->assertJson(['result' => 'external_refund_recorded']);

        $refund = $order->refunds()->sole();
        $this->assertSame('gateway_dashboard', $refund->source);
        $this->assertSame('Refunded in the Stripe dashboard (requested by customer)', $refund->reason);
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame((int) LedgerEntry::sum('debit_sen'), (int) LedgerEntry::sum('credit_sen'));
    }

    public function test_skillmatch_refund_is_tagged_and_its_webhook_not_recorded_twice(): void
    {
        $gateway = $this->fakeStripeFee(null);
        $gateway->shouldReceive('refund')
            ->withArgs(fn ($payment, $amount, $key, $metadata) => isset($metadata['skillmatch_refund_id']))
            ->andReturn(new RefundResult(RefundResult::PENDING, 're_own_1'));
        [$order, $payment] = $this->stripeOrder();
        $this->stripeWebhook('evt_paid', 'checkout.session.completed', $payment, 'paid');

        $admin = User::where('role', 'platform')->firstOrFail();
        $this->withHeader('X-Demo-User', (string) $admin->id)
            ->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'Cancel'])->assertOk();

        $this->stripeObjectWebhook('evt_own', 'refund.created', [
            'id' => 're_own_1', 'object' => 'refund', 'status' => 'pending', 'amount' => 45000,
            'payment_intent' => 'pi_test_'.$payment->id, 'metadata' => ['skillmatch_refund_id' => (string) $order->refunds()->sole()->id],
        ])->assertJson(['result' => 'pending']);

        $this->assertSame(1, $order->refunds()->count());
    }

    public function test_open_dispute_pauses_refunds_and_payouts_and_a_win_lifts_it(): void
    {
        [$order, $payment] = $this->stripeOrder();
        $this->stripeWebhook('evt_paid', 'checkout.session.completed', $payment, 'paid');

        $this->dispute('evt_d1', 'charge.dispute.created', $payment, 'needs_response')->assertJson(['result' => 'dispute_open']);
        $this->assertTrue($order->fresh()->hasOpenDispute());

        $admin = User::where('role', 'platform')->firstOrFail();
        $this->withHeader('X-Demo-User', (string) $admin->id)
            ->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 100, 'reason' => 'x'])
            ->assertUnprocessable();

        $this->dispute('evt_d2', 'charge.dispute.closed', $payment, 'won')->assertJson(['result' => 'dispute_won']);
        $this->assertFalse($order->fresh()->hasOpenDispute());
        $this->dispute('evt_d3', 'charge.dispute.updated', $payment, 'under_review')->assertJson(['result' => 'ignored_dispute_won']);
    }

    public function test_lost_dispute_is_booked_as_a_refund_the_card_network_already_made(): void
    {
        [$order, $payment] = $this->stripeOrder();
        $this->stripeWebhook('evt_paid', 'checkout.session.completed', $payment, 'paid');

        $this->dispute('evt_l1', 'charge.dispute.created', $payment, 'needs_response');
        $this->dispute('evt_l2', 'charge.dispute.closed', $payment, 'lost')->assertJson(['result' => 'dispute_lost']);

        $order->refresh();
        $refund = $order->refunds()->sole();
        $this->assertSame('chargeback', $refund->source);
        $this->assertSame(Refund::SUCCEEDED, $refund->status);
        $this->assertSame('du_test_'.$payment->id, $refund->gateway_reference);
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertTrue($order->splits->every(fn ($s) => $s->status === SplitStatus::Reversed));
        $this->assertSame(0, app(Ledger::class)->balance(Ledger::GATEWAY_CLEARING));
        $this->assertSame((int) LedgerEntry::sum('debit_sen'), (int) LedgerEntry::sum('credit_sen'));
    }

    private function dispute(string $eventId, string $type, Payment $payment, string $status): TestResponse
    {
        return $this->stripeObjectWebhook($eventId, $type, [
            'id' => 'du_test_'.$payment->id,
            'object' => 'dispute',
            'status' => $status,
            'amount' => $payment->amount_sen,
            'reason' => 'fraudulent',
            'payment_intent' => 'pi_test_'.$payment->id,
        ]);
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
                'payment_intent' => 'pi_test_'.$payment->id,
            ]],
        ]);

        return $this->signedStripeCall($body, $secret);
    }

    private function stripeObjectWebhook(string $eventId, string $type, array $object): TestResponse
    {
        return $this->signedStripeCall(json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]), self::SECRET);
    }

    private function signedStripeCall(string $body, string $secret): TestResponse
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}

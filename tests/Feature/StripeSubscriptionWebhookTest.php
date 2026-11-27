<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Ledger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\DemoHelpers;
use Tests\TestCase;

/**
 * Stripe subscription events, signed the way Stripe signs them. No network
 * calls: subscriptions are created directly, then driven by webhooks.
 */
class StripeSubscriptionWebhookTest extends TestCase
{
    use DemoHelpers, RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config([
            'skillmatch.gateways.stripe.webhook_secret' => self::SECRET,
            'skillmatch.gateways.stripe.secret_key' => 'sk_test_dummy',
        ]);
    }

    public function test_card_subscription_starts_and_renews_through_stripe_billing(): void
    {
        $subscription = $this->pendingSubscription(Subscription::COLLECTION_CARD);

        $this->event('evt_cs', 'checkout.session.completed', [
            'id' => 'cs_test_sub', 'object' => 'checkout.session', 'mode' => 'subscription',
            'payment_status' => 'paid', 'amount_total' => 50000, 'subscription' => 'sub_test_1',
            'metadata' => ['skillmatch_subscription_id' => (string) $subscription->id],
        ])->assertJson(['result' => 'subscription_started']);

        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertSame('sub_test_1', $subscription->fresh()->gateway_subscription_id);

        $start = now()->startOfDay()->timestamp;
        $end = now()->startOfDay()->addMonth()->timestamp;
        $this->invoiceEvent('evt_inv1', 'invoice.paid', 'in_test_1', $subscription, $start, $end)->assertJson(['result' => 'subscription_paid']);
        $this->invoiceEvent('evt_inv1_again', 'invoice.paid', 'in_test_1', $subscription, $start, $end)->assertJson(['result' => 'already_paid']);

        $fresh = $subscription->fresh();
        $this->assertSame($end, $fresh->current_period_end->timestamp, "Stripe's period is used");
        $this->assertSame(1, $fresh->invoices()->where('status', SubscriptionInvoice::PAID)->count());
        $this->assertSame(50000, app(Ledger::class)->balance(Ledger::SUBSCRIPTION_REVENUE));

        // Next month's charge.
        $this->invoiceEvent('evt_inv2', 'invoice.paid', 'in_test_2', $subscription, $end, now()->startOfDay()->addMonths(2)->timestamp)
            ->assertJson(['result' => 'subscription_paid']);
        $this->assertSame(now()->startOfDay()->addMonths(2)->timestamp, $subscription->fresh()->current_period_end->timestamp);
        $this->assertBooksBalance();
    }

    public function test_invoice_paid_before_checkout_event_still_activates(): void
    {
        $subscription = $this->pendingSubscription(Subscription::COLLECTION_CARD);

        $this->invoiceEvent('evt_early', 'invoice.paid', 'in_early', $subscription, now()->timestamp, now()->addMonth()->timestamp)
            ->assertJson(['result' => 'subscription_paid']);

        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertSame('sub_test_1', $subscription->fresh()->gateway_subscription_id);
    }

    public function test_failed_card_renewal_starts_the_grace_period_and_deletion_ends_it(): void
    {
        $subscription = $this->activeSubscription(Subscription::COLLECTION_CARD);

        $this->invoiceEvent('evt_fail', 'invoice.payment_failed', 'in_fail', $subscription, now()->timestamp, now()->addMonth()->timestamp)
            ->assertJson(['result' => 'renewal_failed']);
        $fresh = $subscription->fresh();
        $this->assertSame(Subscription::PAST_DUE, $fresh->status);
        $this->assertTrue($fresh->grantsAccess(), 'still on during the grace period');

        // Stripe gives up after its retries and deletes the subscription.
        $this->event('evt_del', 'customer.subscription.deleted', [
            'id' => 'sub_test_1', 'object' => 'subscription', 'status' => 'canceled',
            'metadata' => ['skillmatch_subscription_id' => (string) $subscription->id],
        ])->assertJson(['result' => 'subscription_ended']);

        $this->assertSame(Subscription::EXPIRED, $subscription->fresh()->status);
    }

    public function test_fpx_invoice_paid_at_a_one_off_checkout(): void
    {
        $subscription = $this->pendingSubscription(Subscription::COLLECTION_INVOICE);
        $invoice = $subscription->invoices()->create([
            'amount_sen' => 50000, 'period_start' => now(), 'period_end' => now()->addMonth(),
            'status' => SubscriptionInvoice::OPEN, 'gateway' => 'stripe', 'gateway_reference' => 'cs_test_fpx',
        ]);

        // FPX completes asynchronously: the session completes unpaid, then succeeds.
        $session = [
            'id' => 'cs_test_fpx', 'object' => 'checkout.session', 'mode' => 'payment', 'amount_total' => 50000,
            'metadata' => ['skillmatch_subscription_invoice_id' => (string) $invoice->id],
        ];
        $this->event('evt_fpx1', 'checkout.session.completed', $session + ['payment_status' => 'unpaid'])->assertJson(['result' => 'pending']);
        $this->event('evt_fpx2', 'checkout.session.async_payment_succeeded', $session + ['payment_status' => 'paid'])->assertJson(['result' => 'subscription_paid']);

        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertSame(SubscriptionInvoice::PAID, $invoice->fresh()->status);
    }

    private function pendingSubscription(string $collection): Subscription
    {
        $plan = Plan::where('code', 'provider-t1')->firstOrFail();

        return $this->user('Akademi')->subscriptions()->create([
            'plan_id' => $plan->id, 'status' => Subscription::PENDING, 'gateway' => 'stripe',
            'collection' => $collection, 'price_sen' => $plan->price_sen,
        ]);
    }

    private function activeSubscription(string $collection): Subscription
    {
        $subscription = $this->pendingSubscription($collection);
        $subscription->update([
            'status' => Subscription::ACTIVE, 'gateway_subscription_id' => 'sub_test_1',
            'current_period_start' => now()->subMonth(), 'current_period_end' => now(),
        ]);

        return $subscription;
    }

    /** Newer Stripe API shape: the subscription sits under parent.subscription_details. */
    private function invoiceEvent(string $eventId, string $type, string $invoiceId, Subscription $subscription, int $start, int $end): TestResponse
    {
        return $this->event($eventId, $type, [
            'id' => $invoiceId, 'object' => 'invoice', 'amount_paid' => $type === 'invoice.paid' ? 50000 : 0,
            'parent' => ['subscription_details' => [
                'subscription' => 'sub_test_1',
                'metadata' => ['skillmatch_subscription_id' => (string) $subscription->id],
            ]],
            'lines' => ['object' => 'list', 'data' => [['period' => ['start' => $start, 'end' => $end]]]],
        ]);
    }

    private function event(string $eventId, string $type, array $object): TestResponse
    {
        $body = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}

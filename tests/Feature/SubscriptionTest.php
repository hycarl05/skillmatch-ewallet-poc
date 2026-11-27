<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Ledger;
use Database\Seeders\CommissionRuleSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\DemoHelpers;
use Tests\TestCase;

/** Subscription plans (Tier Plan sheet) through the FakePay simulator. */
class SubscriptionTest extends TestCase
{
    use DemoHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_free_provider_cannot_see_the_demand_marketplace(): void
    {
        $this->as($this->user('Akademi'))->getJson('/api/training-requests')
            ->assertOk()->assertJson(['scope' => 'none', 'requests' => [], 'hidden_count' => 3]);
    }

    public function test_tier_1_unlocks_requests_in_the_providers_state(): void
    {
        $provider = $this->user('Akademi'); // Selangor
        $subscription = $this->subscribeAndPay($provider, 'provider-t1');

        $this->assertSame(Subscription::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->current_period_end->between(now()->addDays(27), now()->addDays(32)));

        $invoice = $subscription->invoices()->first();
        $this->assertSame(SubscriptionInvoice::PAID, $invoice->status);
        $this->assertMatchesRegularExpression('/^SUB-\d{4}-\d{6}$/', $invoice->number);

        $response = $this->as($provider)->getJson('/api/training-requests')->assertOk();
        $response->assertJson(['scope' => 'state', 'hidden_count' => 2]);
        $this->assertSame(['Selangor'], collect($response->json('requests'))->pluck('state')->unique()->values()->all());

        $ledger = app(Ledger::class);
        $this->assertSame(50000, $ledger->balance(Ledger::SUBSCRIPTION_REVENUE));
        $this->assertSame(0, $ledger->balance(Ledger::GATEWAY_CLEARING));
        $this->assertBooksBalance();
    }

    public function test_tier_2_unlocks_every_state(): void
    {
        $freelancer = $this->user('Priya');
        $this->subscribeAndPay($freelancer, 'freelancer-t2');

        $this->as($freelancer)->getJson('/api/training-requests')
            ->assertOk()->assertJson(['scope' => 'all'])->assertJsonCount(3, 'requests');
    }

    public function test_buyers_post_requests_and_see_their_own(): void
    {
        $aisyah = $this->user('Aisyah');

        $this->as($aisyah)->postJson('/api/training-requests', ['title' => 'Public speaking', 'state' => 'Selangor'])->assertCreated();
        $this->as($aisyah)->getJson('/api/training-requests')->assertJson(['scope' => 'mine'])->assertJsonCount(2, 'requests');
        $this->as($this->user('Akademi'))->postJson('/api/training-requests', ['title' => 'x', 'state' => 'Johor'])->assertForbidden();
    }

    public function test_plan_rules(): void
    {
        $provider = $this->user('Akademi');

        $this->as($provider)->postJson('/api/subscriptions', ['plan_id' => $this->plan('freelancer-t1')->id, 'gateway' => 'fake', 'collection' => 'invoice'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
        $this->as($provider)->postJson('/api/subscriptions', ['plan_id' => $this->plan('provider-t1')->id, 'gateway' => 'billplz', 'collection' => 'invoice'])
            ->assertUnprocessable()->assertJsonValidationErrors('gateway');

        $this->subscribeAndPay($provider, 'provider-t1');
        $this->as($provider)->postJson('/api/subscriptions', ['plan_id' => $this->plan('provider-t2')->id, 'gateway' => 'fake', 'collection' => 'invoice'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    }

    public function test_declined_first_payment_leaves_the_user_on_the_free_level(): void
    {
        $provider = $this->user('Akademi');
        $this->as($provider)->postJson('/api/subscriptions', ['plan_id' => $this->plan('provider-t1')->id, 'gateway' => 'fake', 'collection' => 'invoice'])->assertCreated();

        $this->payInvoice(SubscriptionInvoice::firstOrFail(), 'failed')->assertJson(['result' => 'subscription_failed']);

        $this->assertSame(Subscription::EXPIRED, $provider->subscriptions()->first()->status);
        $this->as($provider)->getJson('/api/training-requests')->assertJson(['scope' => 'none']);
    }

    public function test_wrong_amount_does_not_activate(): void
    {
        $this->as($this->user('Akademi'))->postJson('/api/subscriptions', ['plan_id' => $this->plan('provider-t1')->id, 'gateway' => 'fake', 'collection' => 'invoice'])->assertCreated();

        $this->payInvoice(SubscriptionInvoice::firstOrFail(), 'paid', 100)->assertJson(['result' => 'amount_mismatch']);
        $this->assertSame(Subscription::PENDING, Subscription::firstOrFail()->status);
    }

    public function test_invoice_renewal_grace_and_expiry(): void
    {
        $provider = $this->user('Akademi');
        $subscription = $this->subscribeAndPay($provider, 'provider-t1');
        $end = $subscription->current_period_end;

        // A week before the period ends, the renewal invoice goes out.
        $this->travelTo($end->copy()->subDays(6));
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $renewal = $subscription->invoices()->where('status', SubscriptionInvoice::OPEN)->firstOrFail();
        $this->assertTrue($renewal->period_start->eq($end));
        $this->as($provider)->getJson('/api/subscriptions')->assertJsonPath('subscription.pay_url', $renewal->checkout_url);

        // Not paid by the period end: past due, plan still on during the grace period.
        $this->travelTo($end->copy()->addDay());
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $this->assertSame(Subscription::PAST_DUE, $subscription->fresh()->status);
        $this->as($provider)->getJson('/api/training-requests')->assertJson(['scope' => 'state']);

        // Grace over: back to the free level.
        $this->travelTo($end->copy()->addDays(config('skillmatch.subscriptions.grace_days') + 1));
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $this->assertSame(Subscription::EXPIRED, $subscription->fresh()->status);
        $this->assertSame(SubscriptionInvoice::VOID, $renewal->fresh()->status);
        $this->as($provider)->getJson('/api/training-requests')->assertJson(['scope' => 'none']);
    }

    public function test_paying_the_renewal_extends_the_period(): void
    {
        $subscription = $this->subscribeAndPay($this->user('Akademi'), 'provider-t1');
        $end = $subscription->current_period_end;

        $this->travelTo($end->copy()->subDays(3));
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $this->payInvoice($subscription->invoices()->where('status', SubscriptionInvoice::OPEN)->firstOrFail())->assertJson(['result' => 'subscription_paid']);

        $fresh = $subscription->fresh();
        $this->assertSame(Subscription::ACTIVE, $fresh->status);
        $this->assertTrue($fresh->current_period_end->eq($end->copy()->addMonthNoOverflow()));
        $this->assertSame(100000, app(Ledger::class)->balance(Ledger::SUBSCRIPTION_REVENUE));
    }

    public function test_card_subscriber_is_charged_automatically_at_renewal(): void
    {
        $subscription = $this->subscribeAndPay($this->user('Hafiz'), 'freelancer-t1', 'card');
        $end = $subscription->current_period_end;

        $this->travelTo($end->copy()->subDays(2));
        $this->artisan('subscriptions:renew')->assertSuccessful();

        $this->assertSame(2, $subscription->invoices()->where('status', SubscriptionInvoice::PAID)->count());
        $this->assertTrue($subscription->fresh()->current_period_end->gt($end));
    }

    public function test_cancel_keeps_the_plan_until_the_period_ends(): void
    {
        $provider = $this->user('Akademi');
        $subscription = $this->subscribeAndPay($provider, 'provider-t1');

        $this->as($provider)->postJson("/api/subscriptions/{$subscription->id}/cancel")->assertOk()->assertJson(['cancel_at_period_end' => true, 'status' => 'active']);

        $this->travelTo($subscription->current_period_end->copy()->subDays(5));
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $this->assertSame(0, $subscription->invoices()->where('status', SubscriptionInvoice::OPEN)->count(), 'no renewal invoice after cancelling');

        $this->travelTo($subscription->current_period_end->copy()->addMinute());
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $this->assertSame(Subscription::CANCELLED, $subscription->fresh()->status);
    }

    public function test_hrdc_listing_needs_a_freelancer_plan(): void
    {
        $hafiz = $this->user('Hafiz');
        $listing = ['title' => 'Excel for accountants', 'price_sen' => 50000, 'hrdc_claimable' => true];

        $this->as($hafiz)->postJson('/api/trainings', $listing)->assertUnprocessable()->assertJsonValidationErrors('hrdc_claimable');
        $this->as($this->user('Akademi'))->postJson('/api/trainings', $listing)->assertCreated(); // providers can on the free level

        $this->subscribeAndPay($hafiz, 'freelancer-t1');
        $this->as($hafiz)->postJson('/api/trainings', $listing)->assertCreated()->assertJson(['hrdc_claimable' => true]);
    }

    public function test_company_plan_size_and_subscription_discount(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $hr = $this->user('Nadia'); // 2 staff
        $laravel = $this->training('Laravel for Beginners');

        $this->as($hr)->getJson("/api/trainings/{$laravel->id}/quote")->assertJson(['company_discount_sen' => 0]);

        $this->subscribeAndPay($hr, 'company-t1');

        $this->as($hr)->getJson("/api/trainings/{$laravel->id}/quote")->assertJson(['company_discount_sen' => 2250, 'amount_sen' => 42750]);
    }

    private function plan(string $code): Plan
    {
        return Plan::where('code', $code)->firstOrFail();
    }

    private function subscribeAndPay(User $user, string $planCode, string $collection = 'invoice'): Subscription
    {
        $response = $this->as($user)->postJson('/api/subscriptions', [
            'plan_id' => $this->plan($planCode)->id,
            'gateway' => 'fake',
            'collection' => $collection,
        ])->assertCreated();

        $this->assertStringContainsString('/fake-gateway/subscription-invoices/', $response->json('checkout_url'));

        $subscription = Subscription::findOrFail($response->json('subscription.id'));
        $this->payInvoice($subscription->invoices()->firstOrFail())->assertJson(['result' => 'subscription_paid']);

        return $subscription->fresh();
    }
}

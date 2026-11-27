<?php

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\DemoHelpers;
use Tests\TestCase;

/** The client's commission table (Revenue Breakdown sheet) applied to orders. */
class CommissionRulesTest extends TestCase
{
    use DemoHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_non_hrdc_training_takes_20_percent(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Laravel for Beginners'); // RM450, freelancer

        $this->assertSame(2000, $order->commission_bps);
        $this->assertSame(9000, $order->platform_fee_sen);
        $this->assertSame(45000, $order->amount_sen);
        $this->assertSame(36000, $order->splits->firstWhere('role.value', 'freelancer')->amount_sen);
        $this->assertSame('Standard (not HRDC)', $order->commissionRule->name);
    }

    public function test_hrdc_training_takes_15_percent(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Leadership Essentials'); // RM1,200, HRDC, provider + freelancer RM400

        $this->assertSame(1500, $order->commission_bps);
        $this->assertSame(18000, $order->platform_fee_sen);
        $this->assertSame(62000, $order->splits->firstWhere('role.value', 'provider')->amount_sen);
        $this->assertSame(40000, $order->splits->firstWhere('role.value', 'freelancer')->amount_sen);
    }

    public function test_subscribed_company_gets_5_percent_discount_and_18_percent_rate(): void
    {
        $hr = $this->user('Nadia');
        $this->giveSubscription($hr, 'company-t1');

        $quote = $this->as($hr)->getJson('/api/trainings/'.$this->training('Laravel for Beginners')->id.'/quote')->assertOk();
        $quote->assertJson(['gross_sen' => 45000, 'company_discount_sen' => 2250, 'amount_sen' => 42750, 'company_discount_label' => 'Subscribed company (not HRDC)']);
        $this->assertArrayNotHasKey('platform_fee_sen', $quote->json(), 'the buyer never sees the commission');

        $order = $this->buy($hr, 'Laravel for Beginners', ['beneficiary_id' => $this->user('Farid')->id]);

        $this->assertSame(1800, $order->commission_bps);
        $this->assertSame(8100, $order->platform_fee_sen);
        $this->assertSame(2250, $order->company_discount_sen);
        $this->assertSame(42750, $order->amount_sen);
        $this->assertSame(34650, $order->splits->firstWhere('role.value', 'freelancer')->amount_sen); // 77%

        $this->payOrder($order)->assertJson(['result' => 'paid']);
        $this->deliver($order, $this->user('Hafiz'), $hr);
        $this->assertBooksBalance();
    }

    public function test_subscribed_company_large_order_gets_10_percent_discount(): void
    {
        $hr = $this->user('Nadia');
        $this->giveSubscription($hr, 'company-t1');
        Training::create(['seller_id' => $this->user('Akademi')->id, 'title' => 'Leadership academy (cohort of 50)', 'price_sen' => 12000000]);

        $order = $this->buy($hr, 'Leadership academy', ['beneficiary_id' => $this->user('Farid')->id]);

        $this->assertSame(1500, $order->commission_bps);
        $this->assertSame(1200000, $order->company_discount_sen);
        $this->assertSame(10800000, $order->amount_sen);
        $this->assertSame(9000000, $order->splits->firstWhere('role.value', 'provider')->amount_sen); // 75%
    }

    public function test_company_without_subscription_pays_the_standard_rate(): void
    {
        $order = $this->buy($this->user('Nadia'), 'Laravel for Beginners', ['beneficiary_id' => $this->user('Farid')->id]);

        $this->assertSame(2000, $order->commission_bps);
        $this->assertSame(0, $order->company_discount_sen);
    }

    public function test_hrdc_rule_wins_over_the_company_discount(): void
    {
        $hr = $this->user('Nadia');
        $this->giveSubscription($hr, 'company-t1');

        $order = $this->buy($hr, 'Excel for HR Teams', ['beneficiary_id' => $this->user('Farid')->id]); // HRDC

        $this->assertSame(1500, $order->commission_bps);
        $this->assertSame(0, $order->company_discount_sen);
    }

    public function test_changing_a_rule_does_not_change_existing_orders(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Laravel for Beginners');

        CommissionRule::where('name', 'Standard (not HRDC)')->update(['platform_bps' => 2500]);

        $this->assertSame(2000, $order->fresh()->commission_bps);
        $this->assertSame(9000, $order->fresh()->platform_fee_sen);
        $this->assertSame(2500, $this->buy($this->user('Aisyah'), 'Laravel for Beginners')->commission_bps);
    }

    public function test_admin_sees_the_rule_table_and_plans(): void
    {
        $this->as($this->user('SkillMatch Admin'))->getJson('/api/platform/revenue-rules')
            ->assertOk()->assertJsonCount(4, 'commission_rules')->assertJsonCount(8, 'plans');
        $this->as($this->user('Aisyah'))->getJson('/api/platform/revenue-rules')->assertForbidden();
    }

    private function giveSubscription(User $user, string $planCode): Subscription
    {
        $plan = Plan::where('code', $planCode)->firstOrFail();

        return $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::ACTIVE,
            'gateway' => 'fake',
            'collection' => Subscription::COLLECTION_INVOICE,
            'price_sen' => $plan->price_sen,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }
}

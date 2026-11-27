<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SplitStatus;
use App\Services\Ledger;
use Database\Seeders\CommissionRuleSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\DemoHelpers;
use Tests\TestCase;

/**
 * HRDC pays the provider directly, so nothing is paid through SkillMatch.
 * SkillMatch's 15% becomes owed by the provider after the training, and is
 * kept back from their next payout.
 */
class HrdcBookingTest extends TestCase
{
    use DemoHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_hrdc_booking_needs_no_payment(): void
    {
        $response = $this->as($this->user('Nadia'))->postJson('/api/orders', [
            'training_id' => $this->training('Excel for HR Teams')->id, // RM350, HRDC
            'beneficiary_id' => $this->user('Farid')->id,
            'funding' => 'hrdc',
        ])->assertCreated()->assertJsonPath('checkout_url', null)->assertJsonPath('order.funding', 'hrdc');

        $order = \App\Models\Order::findOrFail($response->json('order.id'));
        $this->assertSame(OrderStatus::Booked, $order->status);
        $this->assertSame(0, $order->amount_sen);
        $this->assertSame(5250, $order->platform_fee_sen);
        $this->assertSame(0, $order->payments()->count());
        $this->assertSame(1, $order->splits()->count(), 'only the commission is tracked');
    }

    public function test_only_hrdc_trainings_can_be_booked_through_hrdc(): void
    {
        $this->as($this->user('Aisyah'))->postJson('/api/orders', [
            'training_id' => $this->training('Laravel for Beginners')->id,
            'funding' => 'hrdc',
        ])->assertUnprocessable()->assertJsonValidationErrors('funding');

        $this->as($this->user('Aisyah'))->postJson('/api/orders', [
            'training_id' => $this->training('Excel for HR Teams')->id,
            'funding' => 'hrdc',
            'skillpoints' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('skillpoints');
    }

    public function test_commission_is_owed_after_confirmation_and_kept_from_the_next_payout(): void
    {
        $hr = $this->user('Nadia');
        $provider = $this->user('Akademi');
        $ledger = app(Ledger::class);

        $booking = $this->buy($hr, 'Excel for HR Teams', ['beneficiary_id' => $this->user('Farid')->id, 'funding' => 'hrdc']);
        $this->deliver($booking, $provider, $hr);

        $this->assertSame(OrderStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(5250, $ledger->balance($ledger->commissionDue($provider)));
        $this->assertSame(SplitStatus::Owed, $booking->splits()->first()->status);
        $this->as($provider)->getJson("/api/orders/{$booking->id}")->assertJson(['hrdc_commission_sen' => 5250, 'hrdc_commission_status' => 'owed']);

        // A normal paid order for the same provider: RM350 at the HRDC rate (15%) = RM297.50 share.
        $paid = $this->buy($this->user('Aisyah'), 'Excel for HR Teams');
        $this->payOrder($paid)->assertJson(['result' => 'paid']);
        $this->deliver($paid, $provider, $this->user('Aisyah'));

        $split = $paid->splits()->where('recipient_id', $provider->id)->first();
        $this->assertSame(SplitStatus::PaidOut, $split->status);
        $this->assertSame(5250, $split->payout_offset_sen, 'the owed commission is kept back');
        $this->assertSame(0, $ledger->balance($ledger->commissionDue($provider)));
        $this->assertSame(SplitStatus::PaidOut, $booking->splits()->first()->status, 'commission collected');
        $this->assertBooksBalance();
    }

    public function test_hrdc_booking_cannot_be_refunded_through_skillmatch(): void
    {
        $booking = $this->buy($this->user('Nadia'), 'Excel for HR Teams', ['beneficiary_id' => $this->user('Farid')->id, 'funding' => 'hrdc']);

        $this->as($this->user('SkillMatch Admin'))->postJson("/api/orders/{$booking->id}/refunds", ['amount_sen' => 100, 'reason' => 'test'])
            ->assertUnprocessable();
    }
}

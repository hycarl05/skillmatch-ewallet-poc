<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SplitStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Training;
use App\Models\User;
use App\Payments\Gateways\FakeGateway;
use App\Services\Ledger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_deferred_flow_holds_money_until_confirmed_then_pays_everyone(): void
    {
        $aisyah = $this->user('Aisyah');
        $hafiz = $this->user('Hafiz');
        $order = $this->buy($aisyah, 'Laravel for Beginners', mode: 'deferred');

        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame(45000, $order->amount_sen);

        $this->webhook($order, 'paid')->assertOk()->assertJson(['result' => 'paid']);
        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSplitStatuses($order, SplitStatus::Held);
        $this->assertSame(40500, app(Ledger::class)->balance("held:{$hafiz->id}")); // RM450 - 10% fee
        $this->assertBooksBalance();

        $this->as($hafiz)->postJson("/api/orders/{$order->id}/complete")->assertOk();
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/confirm")->assertOk()->assertJsonPath('status', 'confirmed');
        $order->refresh();

        $this->assertSplitStatuses($order, SplitStatus::PaidOut);
        $this->assertSame(0, app(Ledger::class)->balance("held:{$hafiz->id}"));
        $this->assertSame(0, app(Ledger::class)->balance("payable:{$hafiz->id}"));
        $this->assertSame(0, app(Ledger::class)->balance(Ledger::GATEWAY_CLEARING), 'gateway should hold nothing once everyone is paid');
        $this->assertSame(4500, app(Ledger::class)->balance(Ledger::PLATFORM_BANK));
        $this->assertSame(5000 + 450, $aisyah->fresh()->skillpointBalance(), 'earns 1 point per RM1');
        $this->assertBooksBalance();
    }

    public function test_instant_split_pays_trainers_as_soon_as_payment_arrives(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Leadership Essentials', mode: 'instant');
        $this->webhook($order, 'paid')->assertOk();
        $order->refresh();

        $this->assertSplitStatuses($order, SplitStatus::PaidOut);
        $amounts = $order->splits->pluck('amount_sen', 'role.value')->all();
        $this->assertSame(['provider' => 68000, 'freelancer' => 40000, 'platform' => 12000], $amounts);
        $this->assertSame(0, app(Ledger::class)->balance(Ledger::GATEWAY_CLEARING));
        $this->assertBooksBalance();
    }

    public function test_duplicate_webhooks_are_applied_once(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Laravel for Beginners');

        $this->webhook($order, 'paid', 'evt_same')->assertJson(['result' => 'paid']);
        $this->webhook($order, 'paid', 'evt_same')->assertJson(['result' => 'duplicate']);
        $this->webhook($order, 'paid', 'evt_other')->assertJson(['result' => 'already_paid']);

        $this->assertSame(1, $order->ledgerTransactions()->where('type', 'payment')->count());
        $this->assertBooksBalance();
    }

    public function test_webhook_with_bad_signature_is_rejected(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Laravel for Beginners');
        $body = json_encode(['event_id' => 'evt_x', 'reference' => $order->payments->first()->gateway_reference, 'status' => 'paid', 'amount_sen' => 45000]);

        $this->call('POST', '/api/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => 'forged',
        ], $body)->assertStatus(401);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_wrong_paid_amount_does_not_mark_order_paid(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Laravel for Beginners');
        $payment = $order->payments->first();
        $body = json_encode(['event_id' => 'evt_y', 'reference' => $payment->gateway_reference, 'status' => 'paid', 'amount_sen' => 100]);

        $this->call('POST', '/api/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => app(FakeGateway::class)->sign($body),
        ], $body)->assertJson(['result' => 'amount_mismatch']);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_skillpoints_discount_comes_out_of_platform_fee_only(): void
    {
        $aisyah = $this->user('Aisyah');
        $training = Training::where('title', 'like', 'Laravel%')->first();

        // Fee is RM45 = 4500 points max, even though Aisyah has 5000.
        $this->as($aisyah)->getJson("/api/trainings/{$training->id}/quote")->assertJsonPath('max_points', 4500);
        $this->as($aisyah)->postJson('/api/orders', ['training_id' => $training->id, 'skillpoints' => 5000])
            ->assertUnprocessable();

        $order = $this->buy($aisyah, 'Laravel for Beginners', points: 4500);
        $this->assertSame(40500, $order->amount_sen);
        $this->assertSame(500, $aisyah->fresh()->skillpointBalance());
        $this->assertSame(40500, $order->splits()->where('role', 'freelancer')->value('amount_sen'), 'trainer share is untouched');
        $this->assertNull($order->splits()->where('role', 'platform')->first(), 'SkillMatch gets nothing when the discount uses the whole fee');

        $this->webhook($order, 'paid');
        $this->assertBooksBalance();
    }

    public function test_failed_payment_returns_skillpoints(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->buy($aisyah, 'Laravel for Beginners', points: 1000);

        $this->webhook($order, 'failed')->assertJson(['result' => 'failed']);

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->assertSame(5000, $aisyah->fresh()->skillpointBalance());
    }

    public function test_refund_before_completion_in_deferred_mode_reverses_held_money(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->buy($aisyah, 'Laravel for Beginners', points: 1000, mode: 'deferred');
        $this->webhook($order, 'paid');

        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund", ['reason' => 'Changed my mind'])
            ->assertOk()->assertJsonPath('status', 'refunded');
        $order->refresh();

        $this->assertSplitStatuses($order, SplitStatus::Reversed);
        $this->assertSame(0, app(Ledger::class)->balance(Ledger::GATEWAY_CLEARING));
        $this->assertSame(5000, $aisyah->fresh()->skillpointBalance());
        $this->assertBooksBalance();
    }

    public function test_refund_after_instant_split_leaves_trainer_owing_their_share(): void
    {
        $aisyah = $this->user('Aisyah');
        $hafiz = $this->user('Hafiz');
        $order = $this->buy($aisyah, 'Laravel for Beginners', mode: 'instant');
        $this->webhook($order, 'paid');

        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund")->assertOk();
        $order->refresh();

        $this->assertSame(SplitStatus::ClawbackRequired, $order->splits()->where('recipient_id', $hafiz->id)->first()->status);
        $this->assertSame(40500, app(Ledger::class)->balance("receivable:{$hafiz->id}"));
        $this->assertBooksBalance();
    }

    public function test_buyer_cannot_refund_after_training_is_completed(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->buy($aisyah, 'Laravel for Beginners', mode: 'deferred');
        $this->webhook($order, 'paid');
        $this->as($this->user('Hafiz'))->postJson("/api/orders/{$order->id}/complete")->assertOk();

        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund")->assertUnprocessable();
        $this->as($this->user('SkillMatch Admin'))->postJson("/api/orders/{$order->id}/refund")->assertOk();
    }

    public function test_company_hr_pays_for_their_own_staff_only(): void
    {
        $hr = $this->user('Nadia');
        $farid = $this->user('Farid');
        $training = Training::where('title', 'like', 'Excel%')->first();

        $this->as($hr)->postJson('/api/orders', ['training_id' => $training->id])->assertUnprocessable();
        $this->as($hr)->postJson('/api/orders', ['training_id' => $training->id, 'beneficiary_id' => $this->user('Aisyah')->id])->assertUnprocessable();

        $order = $this->buy($hr, 'Excel for HR Teams', beneficiary: $farid);
        $this->assertSame($farid->id, $order->beneficiary_id);
        $this->as($farid)->getJson("/api/orders/{$order->id}")->assertOk();
    }

    public function test_provider_can_hire_a_freelancer_by_buying_their_training(): void
    {
        $order = $this->buy($this->user('Akademi'), 'Public Speaking Coaching');
        $this->webhook($order, 'paid')->assertJson(['result' => 'paid']);
    }

    public function test_other_users_cannot_see_or_act_on_an_order(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Laravel for Beginners');
        $this->webhook($order, 'paid');

        $this->as($this->user('Mei Ling'))->getJson("/api/orders/{$order->id}")->assertForbidden();
        $this->as($this->user('Priya'))->postJson("/api/orders/{$order->id}/complete")->assertForbidden();
    }

    public function test_completed_orders_are_auto_confirmed_after_the_window(): void
    {
        $order = $this->buy($this->user('Aisyah'), 'Laravel for Beginners', mode: 'deferred');
        $this->webhook($order, 'paid');
        $this->as($this->user('Hafiz'))->postJson("/api/orders/{$order->id}/complete")->assertOk();

        $this->travel(config('skillmatch.auto_confirm_days') + 1)->days();
        $this->artisan('orders:auto-confirm')->assertSuccessful();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSplitStatuses($order->fresh(), SplitStatus::PaidOut);
    }

    public function test_fake_gateway_checkout_page_completes_payment(): void
    {
        $this->withoutVite();
        $order = $this->buy($this->user('Aisyah'), 'Laravel for Beginners');
        $payment = $order->payments->first();

        $this->get("/fake-gateway/{$payment->id}")->assertOk()->assertSee('FakePay');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post("/fake-gateway/{$payment->id}", ['outcome' => 'paid', 'duplicate' => 1])
            ->assertRedirect("/payment/return/{$payment->id}");

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(1, $order->ledgerTransactions()->where('type', 'payment')->count());
    }

    // ---------------------------------------------------------------- helpers

    private function user(string $namePrefix): User
    {
        return User::where('name', 'like', "{$namePrefix}%")->firstOrFail();
    }

    private function as(User $user): static
    {
        return $this->withHeader('X-Demo-User', (string) $user->id);
    }

    private function buy(User $buyer, string $titlePrefix, int $points = 0, ?string $mode = null, ?User $beneficiary = null): Order
    {
        $training = Training::where('title', 'like', "{$titlePrefix}%")->firstOrFail();

        $response = $this->as($buyer)->postJson('/api/orders', array_filter([
            'training_id' => $training->id,
            'skillpoints' => $points,
            'gateway' => 'fake',
            'payout_mode' => $mode,
            'beneficiary_id' => $beneficiary?->id,
        ], fn ($v) => $v !== null))->assertCreated();

        return Order::with('payments', 'splits')->findOrFail($response->json('order.id'));
    }

    private function webhook(Order $order, string $status, ?string $eventId = null): TestResponse
    {
        $payment = $order->payments()->first();
        $body = json_encode([
            'event_id' => $eventId ?? "evt_{$payment->id}_{$status}",
            'reference' => $payment->gateway_reference,
            'status' => $status,
            'amount_sen' => $payment->amount_sen,
        ]);

        return $this->call('POST', '/api/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => app(FakeGateway::class)->sign($body),
        ], $body);
    }

    private function assertSplitStatuses(Order $order, SplitStatus $expected): void
    {
        foreach ($order->splits()->get() as $split) {
            $this->assertSame($expected, $split->status, "split {$split->role->value} should be {$expected->value}");
        }
    }

    private function assertBooksBalance(): void
    {
        $this->assertSame((int) LedgerEntry::sum('debit_sen'), (int) LedgerEntry::sum('credit_sen'), 'ledger debits must equal credits');
    }
}

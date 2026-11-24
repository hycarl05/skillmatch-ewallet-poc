<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SplitStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Training;
use App\Models\User;
use App\Payments\Gateways\FakeGateway;
use App\Payments\RefundResult;
use App\Services\Ledger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class RefundFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_requests_and_admin_approves_a_full_refund_before_completion(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners', points: 1000);

        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Changed my mind'])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('refunds.0.status', 'requested')
            ->assertJsonPath('can.request_refund', false)
            ->assertJsonMissingPath('refunds.0.allocation');
        $this->assertSame(0, $order->ledgerTransactions()->where('type', 'refund')->count(), 'nothing moves until SkillMatch approves');

        $refund = $order->refunds()->sole();
        $this->as($aisyah)->postJson("/api/refunds/{$refund->id}/approve")->assertForbidden();

        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/approve", ['note' => 'OK'])
            ->assertOk()
            ->assertJsonPath('status', 'refunded')
            ->assertJsonPath('refunds.0.status', 'succeeded')
            ->assertJsonPath('refundable_sen', 0);

        $order->refresh();
        $this->assertSplitStatuses($order, SplitStatus::Reversed);
        $this->assertSame(0, $this->ledger()->balance(Ledger::GATEWAY_CLEARING));
        $this->assertSame(0, $this->ledger()->balance(Ledger::REFUNDS_OWED));
        $this->assertSame(0, $this->ledger()->balance(Ledger::PLATFORM_REVENUE));
        $this->assertSame(0, $this->ledger()->balance(Ledger::SKILLPOINTS_DISCOUNT));
        $this->assertSame(5000, $aisyah->fresh()->skillpointBalance());
        $this->assertBooksBalance();
    }

    public function test_admin_can_reject_a_request_and_the_buyer_can_ask_again(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners');

        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'First'])->assertOk();
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Again'])->assertUnprocessable();

        $refund = $order->refunds()->sole();
        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/reject")->assertUnprocessable(); // a note is required
        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/reject", ['note' => 'Training already started'])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('refunds.0.status', 'rejected');

        $this->as($aisyah)->getJson("/api/orders/{$order->id}")->assertJsonPath('refunds.0.review_note', 'Training already started');
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Please reconsider'])->assertOk();
        $this->assertBooksBalance();
    }

    public function test_admin_can_approve_less_than_requested_but_not_more(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners');
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Missed day 2', 'amount_sen' => 22500])->assertOk();
        $refund = $order->refunds()->sole();

        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/approve", ['amount_sen' => 30000])->assertUnprocessable();
        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/approve", ['amount_sen' => 10000])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('refunded_sen', 10000)
            ->assertJsonPath('refundable_sen', 35000);
    }

    public function test_partial_refund_is_shared_pro_rata_and_payouts_send_the_rest(): void
    {
        $aisyah = $this->user('Aisyah');
        // RM1,200: provider RM680, freelancer RM400, SkillMatch RM120.
        $order = $this->paidOrder($aisyah, 'Leadership Essentials', mode: 'deferred');

        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 30000, 'reason' => 'One session cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('refunds.0.status', 'succeeded');

        $refunded = $order->splits()->get()->pluck('refunded_sen', 'role.value')->all();
        $this->assertSame(['provider' => 17000, 'freelancer' => 10000, 'platform' => 3000], $refunded);
        $this->assertSplitStatuses($order, SplitStatus::Held);

        $this->completeAndConfirm($order);

        $this->assertSplitStatuses($order, SplitStatus::PaidOut);
        $this->assertSame(0, $this->ledger()->balance(Ledger::GATEWAY_CLEARING));
        $this->assertSame(9000, $this->ledger()->balance(Ledger::PLATFORM_BANK), 'SkillMatch keeps its commission minus its part of the refund');
        $this->assertBooksBalance();
    }

    public function test_rounding_never_loses_a_sen(): void
    {
        $order = $this->paidOrder($this->user('Aisyah'), 'Leadership Essentials', mode: 'deferred');
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 1001, 'reason' => 'Odd amount'])->assertOk();
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 120000 - 1001, 'reason' => 'The rest'])
            ->assertOk()
            ->assertJsonPath('status', 'refunded');

        $order->refresh();
        foreach ($order->splits as $split) {
            $this->assertSame($split->amount_sen, $split->refunded_sen);
        }
        $this->assertSplitStatuses($order, SplitStatus::Reversed);
        $this->assertSame(0, $this->ledger()->balance(Ledger::GATEWAY_CLEARING));
        $this->assertBooksBalance();
    }

    public function test_refund_after_confirmation_takes_the_trainers_share_back(): void
    {
        $aisyah = $this->user('Aisyah');
        $hafiz = $this->user('Hafiz');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners', mode: 'deferred');
        $this->completeAndConfirm($order);
        $this->assertSame(5450, $aisyah->fresh()->skillpointBalance());

        // After confirmation the buyer can only ask for half; a full refund is SkillMatch's call.
        $this->as($aisyah)->getJson("/api/orders/{$order->id}")->assertJsonPath('refund_policy.buyer_max_sen', 22500);
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'Trainer never showed up'])
            ->assertOk()
            ->assertJsonPath('status', 'refunded');

        $refund = $order->refunds()->sole();
        $hafizPart = collect($refund->allocation)->firstWhere('role', 'freelancer');
        $this->assertTrue($hafizPart['clawback']);
        $this->assertStringStartsWith('fake_reversal_', $hafizPart['reversal_reference']);

        $this->assertSplitStatuses($order->fresh(), SplitStatus::Reversed);
        $this->assertSame(0, $this->ledger()->balance("receivable:{$hafiz->id}"), 'reversal collected');
        $this->assertSame(0, $this->ledger()->balance(Ledger::GATEWAY_CLEARING));
        $this->assertSame(0, $this->ledger()->balance(Ledger::PLATFORM_BANK), 'commission went back too');
        $this->assertSame(5000, $aisyah->fresh()->skillpointBalance(), 'points earned on the order are taken back');
        $this->assertBooksBalance();
    }

    public function test_buyer_can_only_ask_within_the_window_after_confirmation(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners', mode: 'deferred');
        $this->completeAndConfirm($order);

        $this->travel(config('skillmatch.refunds.request_window_days') + 1)->days();

        $this->as($aisyah)->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('can.request_refund', false)
            ->assertJsonPath('refund_blocker', fn ($m) => str_contains($m, 'days after the training is confirmed'));
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Late'])->assertUnprocessable();

        // SkillMatch isn't bound by the window.
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'Goodwill'])->assertOk();
    }

    public function test_refund_after_instant_split_leaves_trainer_owing_their_share(): void
    {
        $aisyah = $this->user('Aisyah');
        $hafiz = $this->user('Hafiz');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners', mode: 'instant');

        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'Cancelled'])->assertOk();

        // The gateway paid Hafiz at collection, so SkillMatch can't reverse it.
        $this->assertSame(SplitStatus::ClawbackRequired, $order->splits()->where('recipient_id', $hafiz->id)->first()->status);
        $this->assertSame(40500, $this->ledger()->balance("receivable:{$hafiz->id}"));
        $this->assertBooksBalance();
    }

    public function test_failed_gateway_refund_stays_owed_until_retried(): void
    {
        $this->fakeGatewayRefunds(
            new RefundResult(RefundResult::FAILED, message: 'Insufficient balance'),
            new RefundResult(RefundResult::SUCCEEDED, 'fake_refund_retry'),
        );
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');

        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'Cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'refunded')
            ->assertJsonPath('refunds.0.status', 'failed')
            ->assertJsonPath('refunds.0.failure_reason', 'Insufficient balance')
            ->assertJsonPath('refunds.0.actions.retry', true);
        $this->assertSame(45000, $this->ledger()->balance(Ledger::REFUNDS_OWED), 'the buyer is still owed the money');
        $this->assertSame(45000, $this->ledger()->balance(Ledger::GATEWAY_CLEARING), 'and it is still at the gateway');

        $refund = $order->refunds()->sole();
        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/retry")
            ->assertOk()
            ->assertJsonPath('refunds.0.status', 'succeeded')
            ->assertJsonPath('refunds.0.attempts', 2);

        $this->assertSame(0, $this->ledger()->balance(Ledger::REFUNDS_OWED));
        $this->assertSame(0, $this->ledger()->balance(Ledger::GATEWAY_CLEARING));
        $this->assertBooksBalance();
    }

    public function test_manual_refund_is_marked_as_sent_by_the_admin(): void
    {
        $this->fakeGatewayRefunds(new RefundResult(RefundResult::MANUAL_REQUIRED, message: 'Send it from the dashboard'));
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');

        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'Cancelled'])
            ->assertJsonPath('refunds.0.status', 'manual_required')
            ->assertJsonPath('refunds.0.actions.mark_sent', true);

        $refund = $order->refunds()->sole();
        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/mark-sent", ['reference' => 'PO-123'])
            ->assertOk()
            ->assertJsonPath('refunds.0.status', 'succeeded')
            ->assertJsonPath('refunds.0.gateway_reference', 'PO-123');

        $this->assertSame(0, $this->ledger()->balance(Ledger::REFUNDS_OWED));
        $this->assertBooksBalance();
    }

    public function test_refund_webhooks_settle_pending_refunds(): void
    {
        $this->fakeGatewayRefunds(
            new RefundResult(RefundResult::PENDING, 'fake_refund_a'),
            new RefundResult(RefundResult::PENDING, 'fake_refund_b'),
        );
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');

        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 10000, 'reason' => 'Part'])
            ->assertJsonPath('refunds.0.status', 'pending');
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 5000, 'reason' => 'Another part'])
            ->assertJsonPath('refunds.0.status', 'pending');

        $this->refundWebhook('fake_refund_a', 'succeeded', 'evt_ra')->assertJson(['result' => 'refund_succeeded']);
        $this->refundWebhook('fake_refund_a', 'succeeded', 'evt_ra')->assertJson(['result' => 'duplicate']);
        $this->assertSame(Refund::SUCCEEDED, Refund::where('gateway_reference', 'fake_refund_a')->value('status'));

        // FPX refunds can fail days later: the money comes back and is owed to the buyer again.
        $this->refundWebhook('fake_refund_b', 'failed', 'evt_rb', 'Bank account closed')->assertJson(['result' => 'refund_failed']);
        $failed = Refund::where('gateway_reference', 'fake_refund_b')->sole();
        $this->assertSame(Refund::FAILED, $failed->status);
        $this->assertSame('Bank account closed', $failed->failure_reason);
        $this->assertSame(5000, $this->ledger()->balance(Ledger::REFUNDS_OWED));
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(30000, $order->fresh()->refundableSen(), 'the failed refund is still committed to the buyer');

        $this->refundWebhook('unknown_ref', 'succeeded', 'evt_rc')->assertJson(['result' => 'unknown_refund']);
        $this->assertBooksBalance();
    }

    public function test_refund_made_in_the_gateway_dashboard_is_recorded_like_our_own(): void
    {
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');
        $payment = $order->payments()->first();

        $this->refundWebhook('dash_re_1', 'succeeded', 'evt_dash', extra: ['payment_reference' => $payment->gateway_reference, 'amount_sen' => 15000])
            ->assertJson(['result' => 'external_refund_recorded']);

        $refund = $order->refunds()->sole();
        $this->assertSame('gateway_dashboard', $refund->source);
        $this->assertSame(Refund::SUCCEEDED, $refund->status);
        $this->assertSame('dash_re_1', $refund->gateway_reference);
        $this->assertSame(13500, $order->splits()->where('role', 'freelancer')->value('refunded_sen'));
        $this->assertSame(30000, $this->ledger()->balance(Ledger::GATEWAY_CLEARING));
        $this->assertSame(0, $this->ledger()->balance(Ledger::REFUNDS_OWED));

        // Later events for the same refund update it instead of recording it again.
        $this->refundWebhook('dash_re_1', 'succeeded', 'evt_dash_2', extra: ['payment_reference' => $payment->gateway_reference, 'amount_sen' => 15000])
            ->assertJson(['result' => 'ignored_succeeded']);
        $this->assertSame(1, $order->refunds()->count());
        $this->assertBooksBalance();
    }

    public function test_dashboard_refund_after_confirmation_takes_the_trainers_share_back(): void
    {
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners', mode: 'deferred');
        $this->completeAndConfirm($order);
        $payment = $order->payments()->first();

        $this->refundWebhook('dash_re_2', 'succeeded', 'evt_dash_c', extra: ['payment_reference' => $payment->gateway_reference, 'amount_sen' => 45000])
            ->assertJson(['result' => 'external_refund_recorded']);

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSplitStatuses($order, SplitStatus::Reversed);
        $this->assertStringStartsWith('fake_reversal_', collect($order->refunds()->sole()->allocation)->firstWhere('role', 'freelancer')['reversal_reference']);
        $this->assertSame(0, $this->ledger()->balance("receivable:{$this->user('Hafiz')->id}"));
        $this->assertBooksBalance();
    }

    public function test_our_own_refund_is_never_recorded_twice_by_its_webhook(): void
    {
        $this->fakeGatewayRefunds(new RefundResult(RefundResult::PENDING, 'fake_refund_own'));
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 10000, 'reason' => 'Part'])->assertOk();
        $refund = $order->refunds()->sole();
        $payment = $order->payments()->first();

        // Webhook that beat us to saving the gateway id: recognised by its metadata.
        $this->refundWebhook('fake_refund_racing', 'succeeded', 'evt_race', extra: [
            'payment_reference' => $payment->gateway_reference, 'amount_sen' => 10000, 'skillmatch_refund_id' => (string) $refund->id,
        ])->assertJson(['result' => 'own_refund_in_progress']);

        $this->assertSame(1, $order->refunds()->count());
        $this->assertBooksBalance();
    }

    public function test_dashboard_refund_bigger_than_what_is_left_is_flagged_not_booked(): void
    {
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 40000, 'reason' => 'Most'])->assertOk();

        $this->refundWebhook('dash_re_3', 'succeeded', 'evt_dash_x', extra: ['payment_reference' => $order->payments()->first()->gateway_reference, 'amount_sen' => 10000])
            ->assertJson(['result' => 'external_refund_exceeds_refundable']);
        $this->assertSame(1, $order->refunds()->count());
    }

    public function test_buyer_who_changed_their_mind_pays_the_processing_fee(): void
    {
        $this->fakePayFee(); // RM450 -> fee RM14.50
        $aisyah = $this->user('Aisyah');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners');

        $this->as($aisyah)->getJson("/api/orders/{$order->id}")->assertJsonPath('refund_policy.payment_fee_sen', 1450);
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Changed my mind'])->assertOk();
        $refund = $order->refunds()->sole();

        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/approve") // default: buyer pays
            ->assertOk()
            ->assertJsonPath('status', 'refunded')
            ->assertJsonPath('refunds.0.gross_sen', 45000)
            ->assertJsonPath('refunds.0.fee_sen', 1450)
            ->assertJsonPath('refunds.0.amount_sen', 43550)
            ->assertJsonPath('refund_fees_recovered_sen', 1450);

        $this->as($aisyah)->getJson("/api/orders/{$order->id}")->assertJsonPath('refunds.0.fee_sen', 1450);
        $this->assertSame(0, $this->ledger()->balance(Ledger::GATEWAY_CLEARING));
        $this->assertSame(0, $this->ledger()->balance(Ledger::PLATFORM_BANK), 'the refund costs SkillMatch nothing');
        $this->assertSame(1450, $this->ledger()->balance(Ledger::REFUND_FEES_RECOVERED));
        $this->assertBooksBalance();
    }

    public function test_partial_refund_carries_its_share_of_the_fee(): void
    {
        $this->fakePayFee();
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');

        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 15000, 'reason' => 'Missed a day', 'fee_paid_by' => 'buyer'])
            ->assertOk()
            ->assertJsonPath('refunds.0.fee_sen', 483) // 1450 x 150/450
            ->assertJsonPath('refunds.0.amount_sen', 14517)
            ->assertJsonPath('refundable_sen', 30000);
        $this->assertBooksBalance();
    }

    public function test_trainer_at_fault_owes_the_fee_and_it_is_kept_from_their_next_payout(): void
    {
        $this->fakePayFee();
        $hafiz = $this->user('Hafiz');
        $first = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners', mode: 'deferred');

        $this->as($this->admin())->postJson("/api/orders/{$first->id}/refunds", ['amount_sen' => 45000, 'reason' => 'Trainer cancelled', 'fee_paid_by' => 'trainer'])
            ->assertOk()
            ->assertJsonPath('refunds.0.amount_sen', 45000)
            ->assertJsonPath('refunds.0.allocation.0.fee_debt_sen', 1450);
        $this->assertSame(1450, $this->ledger()->balance("fee_debt:{$hafiz->id}"));
        $this->as($hafiz)->getJson('/api/wallet')->assertJsonPath('earnings.owed_back_sen', 1450);

        // Hafiz's next sale pays out RM405 minus the RM14.50 he owes.
        $second = $this->paidOrder($this->user('Akademi'), 'Laravel for Beginners', mode: 'deferred');
        $this->completeAndConfirm($second);

        $split = $second->splits()->where('recipient_id', $hafiz->id)->sole();
        $this->assertSame(SplitStatus::PaidOut, $split->status);
        $this->assertSame(1450, $split->payout_offset_sen);
        $this->assertSame(0, $this->ledger()->balance("fee_debt:{$hafiz->id}"));
        $this->assertSame(0, $this->ledger()->balance(Ledger::GATEWAY_CLEARING));
        $this->assertBooksBalance();
    }

    public function test_goodwill_refund_is_paid_by_skillmatch(): void
    {
        $this->fakePayFee();
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');

        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'Goodwill']) // default: SkillMatch
            ->assertOk()
            ->assertJsonPath('refunds.0.amount_sen', 45000)
            ->assertJsonPath('refunds.0.fee_paid_by', 'skillmatch');
        $this->assertSame(-1450, $this->ledger()->balance(Ledger::PLATFORM_BANK));
        $this->assertBooksBalance();
    }

    public function test_after_completion_the_buyer_can_ask_for_at_most_half(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners', mode: 'deferred');
        $this->as($order->training->seller)->postJson("/api/orders/{$order->id}/complete")->assertOk();

        $this->as($aisyah)->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('refund_policy.buyer_max_sen', 22500)
            ->assertJsonPath('refund_policy.buyer_max_percent', 50);
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Too much', 'amount_sen' => 30000])->assertUnprocessable();
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Day 2 was poor'])->assertOk();

        $refund = $order->refunds()->sole();
        $this->assertSame(22500, $refund->gross_sen);
        $this->as($this->admin())->postJson("/api/refunds/{$refund->id}/approve")->assertOk();

        $this->as($aisyah)->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('can.request_refund', false)
            ->assertJsonPath('refund_blocker', fn ($m) => str_contains($m, 'up to 50%'));
    }

    public function test_cannot_refund_more_than_was_paid(): void
    {
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45001, 'reason' => 'Too much'])->assertUnprocessable();
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 45000, 'reason' => 'All'])->assertOk();
        $this->as($this->admin())->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 1, 'reason' => 'Again'])->assertUnprocessable();
    }

    public function test_only_the_buyer_can_request_and_only_skillmatch_can_refund(): void
    {
        $order = $this->paidOrder($this->user('Aisyah'), 'Laravel for Beginners');

        $this->as($this->user('Hafiz'))->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'x'])->assertForbidden();
        $this->as($this->user('Aisyah'))->postJson("/api/orders/{$order->id}/refunds", ['amount_sen' => 100, 'reason' => 'x'])->assertForbidden();
    }

    public function test_open_request_holds_back_auto_confirm(): void
    {
        $aisyah = $this->user('Aisyah');
        $order = $this->paidOrder($aisyah, 'Laravel for Beginners', mode: 'deferred');
        $this->as($this->user('Hafiz'))->postJson("/api/orders/{$order->id}/complete")->assertOk();
        $this->as($aisyah)->postJson("/api/orders/{$order->id}/refund-requests", ['reason' => 'Not delivered'])->assertOk();

        $this->travel(config('skillmatch.auto_confirm_days') + 1)->days();
        $this->artisan('orders:auto-confirm')->assertSuccessful();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    // ---------------------------------------------------------------- helpers

    /** Turn on FakePay's simulated fee: 3% + RM1. */
    private function fakePayFee(): void
    {
        config(['skillmatch.gateways.fake.fee_percent' => 3, 'skillmatch.gateways.fake.fee_fixed_sen' => 100]);
    }

    private function fakeGatewayRefunds(RefundResult ...$results): Mockery\MockInterface
    {
        $gateway = Mockery::mock(FakeGateway::class)->makePartial();
        $gateway->shouldReceive('refund')->andReturn(...$results);
        $this->app->instance(FakeGateway::class, $gateway);

        return $gateway;
    }

    private function user(string $namePrefix): User
    {
        return User::where('name', 'like', "{$namePrefix}%")->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('role', 'platform')->firstOrFail();
    }

    private function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    private function as(User $user): static
    {
        return $this->withHeader('X-Demo-User', (string) $user->id);
    }

    private function paidOrder(User $buyer, string $titlePrefix, int $points = 0, string $mode = 'deferred'): Order
    {
        $training = Training::where('title', 'like', "{$titlePrefix}%")->firstOrFail();

        $response = $this->as($buyer)->postJson('/api/orders', [
            'training_id' => $training->id,
            'skillpoints' => $points,
            'gateway' => 'fake',
            'payout_mode' => $mode,
        ])->assertCreated();

        $order = Order::findOrFail($response->json('order.id'));
        $payment = $order->payments()->first();
        $body = json_encode(['event_id' => "evt_pay_{$payment->id}", 'reference' => $payment->gateway_reference, 'status' => 'paid', 'amount_sen' => $payment->amount_sen]);
        $this->signedFakeWebhook($body)->assertJson(['result' => 'paid']);

        return $order->fresh();
    }

    private function completeAndConfirm(Order $order): void
    {
        $this->as($order->training->seller)->postJson("/api/orders/{$order->id}/complete")->assertOk();
        $this->as($order->buyer)->postJson("/api/orders/{$order->id}/confirm")->assertOk();
    }

    private function refundWebhook(string $reference, string $status, string $eventId, ?string $failureReason = null, array $extra = []): TestResponse
    {
        return $this->signedFakeWebhook(json_encode(array_filter([
            'type' => 'refund',
            'event_id' => $eventId,
            'reference' => $reference,
            'status' => $status,
            'failure_reason' => $failureReason,
        ] + $extra)));
    }

    private function signedFakeWebhook(string $body): TestResponse
    {
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

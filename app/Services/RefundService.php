<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PayoutMode;
use App\Enums\SplitStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderSplit;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\RefundResult;
use App\Payments\ReversesPayouts;
use App\Payments\WebhookResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Refunds, from the buyer's request to the money being back with them.
 *
 *   1. The buyer asks (request), or SkillMatch refunds directly (refundNow).
 *   2. SkillMatch approves or rejects the request.
 *   3. On approval the amount is split across the order's shares pro rata and
 *      booked as owed to the buyer (ledger "refunds_owed"). The refund's share
 *      of the gateway fee is paid by the buyer (deducted), the trainer (owed,
 *      kept from their next payout) or SkillMatch, depending on the reason.
 *   4. The gateway is asked to refund. Accepted: the owed money leaves the
 *      gateway. Failed or not supported by API: it stays owed until SkillMatch
 *      retries or marks it as sent by hand.
 *   5. Shares a trainer had already been paid are taken back from them
 *      (Stripe Connect transfer reversal). If that's impossible they stay
 *      "owed back" (receivable).
 *
 * A refund made in the gateway dashboard arrives by webhook and is recorded
 * the same way (steps 3 and 5), so the books always match the gateway.
 *
 * Gateway calls never run inside a DB transaction.
 */
class RefundService
{
    /** Order statuses a refund can be made from. */
    private const REFUNDABLE = [OrderStatus::Paid, OrderStatus::Completed, OrderStatus::Confirmed];

    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly Ledger $ledger,
        private readonly OrderService $orders,
    ) {}

    // ------------------------------------------------------------------ buyer

    public function request(Order $order, User $buyer, ?int $amountSen, string $reason): Refund
    {
        abort_unless($order->buyer_id === $buyer->id, 403, 'Only the buyer can ask for a refund.');

        if ($blocker = $this->requestBlocker($order)) {
            throw ValidationException::withMessages(['order' => $blocker]);
        }

        $max = $this->buyerMaxSen($order);
        $amountSen ??= $max;
        if ($amountSen > $max) {
            throw ValidationException::withMessages(['amount_sen' => 'At this stage you can ask for up to '.$this->rm($max).'.']);
        }
        $this->assertAmount($order, $amountSen);

        // The fee is worked out when SkillMatch approves, once the reason is known.
        return $order->refunds()->create([
            'payment_id' => $this->paidPayment($order)->id,
            'source' => 'buyer',
            'requested_by' => $buyer->id,
            'amount_sen' => $amountSen,
            'gross_sen' => $amountSen,
            'status' => Refund::REQUESTED,
            'reason' => $reason,
        ]);
    }

    /**
     * The most the buyer can ask for now: a % of what they paid that depends on
     * how far the order has gone, minus what was already refunded.
     */
    public function buyerMaxSen(Order $order): int
    {
        $percent = (int) (config('skillmatch.refunds.buyer_max_percent')[$order->status->value] ?? 0);
        $alreadyRefunded = $order->amount_sen - $order->refundableSen();
        $cap = intdiv($order->amount_sen * $percent, 100) - $alreadyRefunded;

        return max(0, min($cap, $order->refundableSen()));
    }

    /**
     * The refund's share of the gateway's processing fee, and what the buyer
     * gets once it's paid by whoever the rule says.
     *
     * @return array{amount_sen: int, fee_sen: int}
     */
    public function feeTerms(Order $order, int $grossSen, string $paidBy): array
    {
        $fee = (int) $this->paidPayment($order)->gateway_fee_sen; // unknown yet = 0
        $share = $order->amount_sen > 0 ? (int) round($fee * $grossSen / $order->amount_sen) : 0;
        $amount = $paidBy === Refund::FEE_BUYER ? $grossSen - $share : $grossSen;

        if ($amount < 1) {
            throw ValidationException::withMessages(['amount_sen' => 'This refund is smaller than its share of the processing fee.']);
        }

        return ['amount_sen' => $amount, 'fee_sen' => $share];
    }

    /** Why the buyer can't ask for a refund right now, or null if they can. */
    public function requestBlocker(Order $order): ?string
    {
        if ($blocker = $this->refundBlocker($order)) {
            return $blocker;
        }

        if ($order->refunds()->where('status', Refund::REQUESTED)->exists()) {
            return 'You already have a refund request waiting for SkillMatch.';
        }

        $days = (int) config('skillmatch.refunds.request_window_days');
        if ($order->status === OrderStatus::Confirmed && $order->confirmed_at?->lt(now()->subDays($days))) {
            return "Refunds can be requested up to {$days} days after the training is confirmed. Contact SkillMatch support.";
        }

        if ($this->buyerMaxSen($order) <= 0) {
            $percent = config('skillmatch.refunds.buyer_max_percent')[$order->status->value] ?? 0;

            return "After the training is marked completed you can be refunded up to {$percent}% of what you paid, and that has been reached. Contact SkillMatch support.";
        }

        return null;
    }

    // ------------------------------------------------------------------ admin

    /** Why SkillMatch can't refund this order right now, or null if it can. */
    public function refundBlocker(Order $order): ?string
    {
        return match (true) {
            ! in_array($order->status, self::REFUNDABLE, true) => "A {$order->status->value} order can't be refunded.",
            $order->hasOpenDispute() => 'The payment is disputed with the card issuer, which decides the outcome. Respond in the gateway dashboard.',
            $order->refundableSen() <= 0 => 'Everything paid for this order has already been refunded.',
            default => null,
        };
    }

    /** SkillMatch refunds without a request from the buyer. */
    public function refundNow(Order $order, User $admin, int $amountSen, string $reason, string $feePaidBy = Refund::FEE_SKILLMATCH): Refund
    {
        $this->assertPlatform($admin);
        $this->refreshFee($order);

        $refund = DB::transaction(function () use ($order, $admin, $amountSen, $reason, $feePaidBy) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($blocker = $this->refundBlocker($order)) {
                throw ValidationException::withMessages(['order' => $blocker]);
            }
            $this->assertAmount($order, $amountSen);

            $refund = $order->refunds()->create([
                'payment_id' => $this->paidPayment($order)->id,
                'source' => 'platform',
                'requested_by' => $admin->id,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'gross_sen' => $amountSen,
                'fee_paid_by' => $feePaidBy,
                'status' => Refund::PROCESSING,
                'reason' => $reason,
            ] + $this->feeTerms($order, $amountSen, $feePaidBy));
            $this->allocate($order, $refund);

            return $refund;
        });

        return $this->send($refund);
    }

    /** Approve a buyer's request, optionally for less than they asked. */
    public function approve(Refund $refund, User $admin, ?int $amountSen, ?string $note, string $feePaidBy = Refund::FEE_BUYER): Refund
    {
        $this->assertPlatform($admin);
        $this->refreshFee($refund->order);

        $refund = DB::transaction(function () use ($refund, $admin, $amountSen, $note, $feePaidBy) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $this->expectStatus($refund, Refund::REQUESTED);

            if ($blocker = $this->refundBlocker($order)) {
                throw ValidationException::withMessages(['order' => $blocker]);
            }
            $amountSen ??= $refund->gross_sen;
            if ($amountSen > $refund->gross_sen) {
                throw ValidationException::withMessages(['amount_sen' => 'You can approve up to the amount the buyer asked for. To refund more, use Refund on the order.']);
            }
            $this->assertAmount($order, $amountSen);

            $refund->update([
                'gross_sen' => $amountSen,
                'fee_paid_by' => $feePaidBy,
                'status' => Refund::PROCESSING,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ] + $this->feeTerms($order, $amountSen, $feePaidBy));
            $this->allocate($order, $refund);

            return $refund;
        });

        return $this->send($refund);
    }

    public function reject(Refund $refund, User $admin, string $note): Refund
    {
        $this->assertPlatform($admin);

        return DB::transaction(function () use ($refund, $admin, $note) {
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $this->expectStatus($refund, Refund::REQUESTED);

            $refund->update([
                'status' => Refund::REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $refund;
        });
    }

    /** Ask the gateway again after a failure, and retry any clawback that didn't go through. */
    public function retry(Refund $refund, User $admin): Refund
    {
        $this->assertPlatform($admin);

        if ($refund->status === Refund::FAILED) {
            return $this->send($refund);
        }

        if (in_array($refund->status, [Refund::PENDING, Refund::SUCCEEDED], true) && $refund->outstandingClawbacks() !== []) {
            $this->collectClawbacks($refund);

            return $refund->fresh();
        }

        throw ValidationException::withMessages(['refund' => 'There is nothing to retry on this refund.']);
    }

    /** SkillMatch refunded the buyer outside the API (gateway dashboard, bank transfer). */
    public function markSent(Refund $refund, User $admin, ?string $reference): Refund
    {
        $this->assertPlatform($admin);

        DB::transaction(function () use ($refund, $reference) {
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! in_array($refund->status, Refund::OWED, true)) {
                throw ValidationException::withMessages(['refund' => "A {$refund->status} refund can't be marked as sent."]);
            }
            $this->bookSent($refund, Refund::SUCCEEDED, $reference ?: $refund->gateway_reference);
        });

        $this->collectClawbacks($refund);

        return $refund->fresh();
    }

    // --------------------------------------------------------------- webhooks

    /** Apply a verified refund or dispute webhook. Runs inside the webhook's DB transaction. */
    public function applyWebhook(string $gatewayName, WebhookResult $result): string
    {
        return match ($result->kind) {
            WebhookResult::KIND_REFUND => $this->applyRefundUpdate($gatewayName, $result),
            WebhookResult::KIND_DISPUTE => $this->applyDispute($gatewayName, $result),
            default => 'ignored',
        };
    }

    private function applyRefundUpdate(string $gatewayName, WebhookResult $result): string
    {
        $refund = Refund::where('gateway_reference', $result->reference)
            ->whereHas('payment', fn ($q) => $q->where('gateway', $gatewayName))
            ->lockForUpdate()
            ->first();

        if (! $refund) {
            // One of ours whose gateway id isn't saved yet: send() is still
            // booking it and records the status the gateway returned.
            if ($result->ownReference && Refund::whereKey($result->ownReference)->exists()) {
                return 'own_refund_in_progress';
            }

            return $this->importGatewayRefund($gatewayName, $result);
        }

        if ($result->status === WebhookResult::SUCCEEDED) {
            if ($refund->status === Refund::PENDING) {
                $refund->update(['status' => Refund::SUCCEEDED, 'completed_at' => now()]);

                return 'refund_succeeded';
            }

            return "ignored_{$refund->status}";
        }

        if ($result->status === WebhookResult::FAILED) {
            if (! in_array($refund->status, [Refund::PENDING, Refund::SUCCEEDED], true)) {
                return "ignored_{$refund->status}";
            }

            // The money came back to the gateway balance: the buyer is owed it again.
            $lines = [[Ledger::REFUNDS_OWED, 0, $refund->amount_sen]];
            foreach ($refund->funding ?? [] as $account => $sen) {
                $lines[] = [$account, $sen, 0];
            }
            $this->ledger->post('refund_failed', "Refund for {$refund->order->reference} failed at the gateway: ".($result->message ?? 'no reason given'), $refund->order, $lines);
            $refund->update(['status' => Refund::FAILED, 'failure_reason' => $result->message ?? 'Failed at the gateway', 'completed_at' => null]);

            return 'refund_failed';
        }

        return 'pending';
    }

    /**
     * Someone refunded in the gateway dashboard, outside SkillMatch. Record it
     * exactly like a SkillMatch refund (shares, ledger, clawbacks) so the books
     * still match the gateway. It's already decided, so there's no approval step.
     */
    private function importGatewayRefund(string $gatewayName, WebhookResult $result): string
    {
        // A failed dashboard refund moved no money: nothing to record.
        if ($result->status === WebhookResult::FAILED) {
            return 'ignored_failed_external_refund';
        }

        $payment = $result->gatewayPaymentId
            ? Payment::where('gateway', $gatewayName)
                ->where(fn ($q) => $q->where('gateway_payment_id', $result->gatewayPaymentId)->orWhere('gateway_reference', $result->gatewayPaymentId))
                ->first()
            : null;

        if (! $payment || ! in_array($payment->status, ['paid', 'refunded'], true)) {
            Log::warning('Refund webhook for a payment SkillMatch does not know', ['gateway' => $gatewayName, 'refund' => $result->reference]);

            return 'unknown_refund';
        }

        $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
        $refundable = $order->refundableSen();
        $amount = (int) $result->amountSen;

        if ($amount < 1 || $amount > $refundable) {
            Log::error('Dashboard refund is larger than what is left to refund: check the order by hand', [
                'order' => $order->reference, 'refund' => $result->reference, 'amount' => $amount, 'refundable' => $refundable,
            ]);

            return 'external_refund_exceeds_refundable';
        }

        $refund = $order->refunds()->create([
            'payment_id' => $payment->id,
            'source' => 'gateway_dashboard',
            'amount_sen' => $amount,
            'gross_sen' => $amount,
            'fee_paid_by' => Refund::FEE_SKILLMATCH,
            'status' => Refund::PROCESSING,
            'reason' => 'Refunded in the '.ucfirst($gatewayName).' dashboard'.($result->message ? ' ('.str_replace('_', ' ', $result->message).')' : ''),
            'attempts' => 1,
        ]);
        $this->allocate($order, $refund);
        $this->bookSent($refund, $result->status === WebhookResult::SUCCEEDED ? RefundResult::SUCCEEDED : RefundResult::PENDING, $result->reference);

        DB::afterCommit(fn () => $this->collectClawbacks($refund));

        return 'external_refund_recorded';
    }

    private function applyDispute(string $gatewayName, WebhookResult $result): string
    {
        $payment = $result->gatewayPaymentId
            ? Payment::where('gateway', $gatewayName)->where('gateway_payment_id', $result->gatewayPaymentId)->lockForUpdate()->first()
            : null;

        if (! $payment) {
            return 'unknown_payment';
        }

        // A closed dispute doesn't reopen.
        if (in_array($payment->dispute_status, ['won', 'lost'], true)) {
            return "ignored_dispute_{$payment->dispute_status}";
        }

        $payment->update([
            'dispute_reference' => $result->reference,
            'dispute_status' => $result->status,
            'dispute_reason' => $result->message,
            'dispute_amount_sen' => $result->amountSen,
        ]);
        $order = $payment->order;

        if ($result->status === WebhookResult::DISPUTE_WON) {
            // Payouts were paused while the dispute was open.
            DB::afterCommit(fn () => $this->orders->payOutReleased($order->fresh()));

            return 'dispute_won';
        }

        if ($result->status === WebhookResult::DISPUTE_LOST) {
            return $this->bookLostChargeback($order, $payment, $result);
        }

        return 'dispute_open';
    }

    /**
     * The card issuer gave the money back to the buyer. It's a refund the
     * gateway has already made, so it's booked as sent straight away.
     */
    private function bookLostChargeback(Order $order, Payment $payment, WebhookResult $result): string
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $amount = min((int) $result->amountSen, $order->refundableSen());

        if ($amount <= 0) {
            Log::error('Lost chargeback on an order that was already refunded: SkillMatch paid twice', ['order' => $order->reference, 'dispute' => $result->reference]);

            return 'dispute_lost_already_refunded';
        }

        $refund = $order->refunds()->create([
            'payment_id' => $payment->id,
            'source' => 'chargeback',
            'amount_sen' => $amount,
            'gross_sen' => $amount,
            'fee_paid_by' => Refund::FEE_SKILLMATCH,
            'status' => Refund::PROCESSING,
            'reason' => 'Chargeback lost'.($result->message ? " ({$result->message})" : ''),
        ]);
        $this->allocate($order, $refund);
        $this->bookSent($refund, Refund::SUCCEEDED, $result->reference);

        DB::afterCommit(fn () => $this->collectClawbacks($refund));

        return 'dispute_lost';
    }

    // ---------------------------------------------------------------- the work

    /**
     * Split the refund across the order's shares in proportion to what each
     * still has, and book it as owed to the buyer. Caller holds the order lock.
     */
    private function allocate(Order $order, Refund $refund): void
    {
        $splits = $order->splits()->with('recipient')->get()->filter(fn (OrderSplit $s) => $s->remainingSen() > 0)->values();
        $remaining = $splits->sum(fn (OrderSplit $s) => $s->remainingSen());
        $amount = $refund->gross_sen;
        $isFinal = $amount === $remaining;

        $parts = $this->shareOut($amount, $splits->map(fn (OrderSplit $s) => $s->remainingSen())->all());

        $lines = [];
        $funding = [Ledger::GATEWAY_CLEARING => 0, Ledger::PLATFORM_BANK => 0];
        $allocation = [];

        foreach ($splits as $i => $split) {
            $part = $parts[$i];
            if ($part === 0) {
                continue;
            }

            $clawback = false;
            $source = Ledger::GATEWAY_CLEARING;
            $emptied = $split->remainingSen() === $part;
            $status = $emptied ? SplitStatus::Reversed : $split->status;

            if (! $split->recipient) {
                // SkillMatch's commission. Once settled it comes back out of SkillMatch's bank.
                $lines[] = [Ledger::PLATFORM_REVENUE, $part, 0];
                if ($split->status === SplitStatus::PaidOut) {
                    $source = Ledger::PLATFORM_BANK;
                }
            } else {
                $account = match ($split->status) {
                    SplitStatus::Held => $this->ledger->held($split->recipient),
                    SplitStatus::Released => $this->ledger->payable($split->recipient),
                    // Already with the trainer: the buyer is refunded from the
                    // gateway balance and the trainer owes the part back.
                    default => $this->ledger->receivable($split->recipient),
                };
                $lines[] = [$account, $part, 0];

                if (in_array($split->status, [SplitStatus::PaidOut, SplitStatus::ClawbackRequired], true)) {
                    $clawback = true;
                    $status = SplitStatus::ClawbackRequired;
                }
            }

            $funding[$source] += $part;
            $split->update(['refunded_sen' => $split->refunded_sen + $part, 'status' => $status]);
            $allocation[] = [
                'split_id' => $split->id,
                'role' => $split->role->value,
                'recipient' => $split->recipient?->name,
                'amount_sen' => $part,
                'clawback' => $clawback,
                'reversal_reference' => null,
                'clawback_error' => null,
            ];
        }

        $this->applyFeeRule($refund, $splits, $parts, $lines, $funding, $allocation);

        // What the buyer gets (the gross amount, minus the fee when they pay it).
        $lines[] = [Ledger::REFUNDS_OWED, 0, $refund->amount_sen];

        // Full refund: the SkillPoints come back, so SkillMatch no longer pays the discount.
        if ($isFinal && $order->discount_sen > 0) {
            $lines[] = [Ledger::PLATFORM_REVENUE, $order->discount_sen, 0];
            $lines[] = [Ledger::SKILLPOINTS_DISCOUNT, 0, $order->discount_sen];
        }

        $this->ledger->post('refund', 'Refund of '.$this->rm($amount)." for {$order->reference}: {$refund->reason}", $order, $lines);
        $refund->allocation = $allocation;
        $refund->funding = array_filter($funding);
        $refund->save();

        if ($isFinal) {
            $order->update(['status' => OrderStatus::Refunded, 'refunded_at' => now()]);
            $order->payments()->where('status', 'paid')->update(['status' => 'refunded']);
            $this->orders->restoreSkillpoints($order, 'refunded');
            $this->takeBackEarnedSkillpoints($order);
        }
    }

    /**
     * The refund's share of the processing fee (Stripe keeps the whole fee).
     *   buyer:      withheld from the refund; stays with SkillMatch
     *   trainer:    the buyer gets everything; the trainers owe it (fee_debt),
     *               kept back from their next payout
     *   skillmatch: nothing to do, SkillMatch absorbs it
     */
    private function applyFeeRule(Refund $refund, $splits, array $parts, array &$lines, array &$funding, array &$allocation): void
    {
        $fee = $refund->fee_sen;
        if ($fee <= 0 || $refund->fee_paid_by === Refund::FEE_SKILLMATCH) {
            return;
        }

        if ($refund->fee_paid_by === Refund::FEE_BUYER) {
            $lines[] = [Ledger::REFUND_FEES_RECOVERED, 0, $fee];

            // Less leaves for the buyer. What stays at the gateway now belongs to SkillMatch.
            $fromClearing = min($fee, $funding[Ledger::GATEWAY_CLEARING]);
            $funding[Ledger::GATEWAY_CLEARING] -= $fromClearing;
            $funding[Ledger::PLATFORM_BANK] -= $fee - $fromClearing;
            $lines[] = [Ledger::PLATFORM_BANK, $fromClearing, 0];
            $lines[] = [Ledger::GATEWAY_CLEARING, 0, $fromClearing];
            $refund->funding = array_filter($funding);

            return;
        }

        // Trainer: shared between the trainers in proportion to their part of this refund.
        $trainerParts = [];
        foreach ($splits as $i => $split) {
            if ($split->recipient && $parts[$i] > 0) {
                $trainerParts[$i] = $parts[$i];
            }
        }
        if ($trainerParts === []) {
            $refund->fee_paid_by = Refund::FEE_SKILLMATCH; // nobody to charge

            return;
        }

        foreach ($this->shareOut($fee, $trainerParts) as $i => $debt) {
            if ($debt === 0) {
                continue;
            }
            $split = $splits[$i];
            $lines[] = [$this->ledger->feeDebt($split->recipient), $debt, 0];
            $lines[] = [Ledger::REFUND_FEES_RECOVERED, 0, $debt];

            $index = array_search($split->id, array_column($allocation, 'split_id'), true);
            $allocation[$index]['fee_debt_sen'] = $debt;
        }
    }

    /**
     * Split $total in proportion to $weights, rounding with the largest
     * remainder so the parts add up exactly and none exceeds its weight.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, int>
     */
    private function shareOut(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $parts = [];
        $fractions = [];
        foreach ($weights as $key => $weight) {
            $parts[$key] = $sum > 0 ? intdiv($total * $weight, $sum) : 0;
            $fractions[$key] = $sum > 0 ? ($total * $weight) % $sum : 0;
        }
        arsort($fractions);
        $left = $total - array_sum($parts);
        foreach (array_keys($fractions) as $key) {
            if ($left-- <= 0) {
                break;
            }
            $parts[$key]++;
        }

        return $parts;
    }

    /** Make sure the gateway's fee is known before working out who pays it. */
    private function refreshFee(Order $order): void
    {
        if ($payment = $order->payments()->whereIn('status', ['paid', 'refunded'])->latest('id')->first()) {
            $this->orders->recordProcessingFee($payment);
        }
    }

    /** Ask the gateway to refund, then book the outcome. */
    private function send(Refund $refund): Refund
    {
        $refund->increment('attempts');
        $refund->loadMissing('order', 'payment');

        try {
            $result = $this->gateways->gateway($refund->payment->gateway)->refund(
                $refund->payment,
                $refund->amount_sen,
                "refund-{$refund->order->reference}-{$refund->id}-{$refund->attempts}",
                // Lets the webhook tell our refunds from ones made in the gateway dashboard.
                ['skillmatch_refund_id' => (string) $refund->id],
            );
        } catch (Throwable $e) {
            $result = new RefundResult(RefundResult::FAILED, message: $e->getMessage());
        }

        DB::transaction(function () use ($refund, $result) {
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! in_array($refund->status, [Refund::PROCESSING, Refund::FAILED], true)) {
                return; // a webhook got there first
            }

            if ($result->accepted()) {
                $this->bookSent($refund, $result->status, $result->reference);
            } else {
                $refund->update([
                    'status' => $result->status === RefundResult::MANUAL_REQUIRED ? Refund::MANUAL_REQUIRED : Refund::FAILED,
                    'gateway_reference' => $result->reference ?? $refund->gateway_reference,
                    'failure_reason' => $result->message,
                ]);
            }
        });

        if ($result->accepted()) {
            $this->collectClawbacks($refund);
        } else {
            Log::notice('Refund not sent by the gateway', ['refund' => $refund->id, 'status' => $result->status, 'message' => $result->message]);
        }

        return $refund->fresh();
    }

    /** The money left the gateway (or SkillMatch's bank) for the buyer. */
    private function bookSent(Refund $refund, string $status, ?string $reference): void
    {
        $refund->loadMissing('order');

        $lines = [[Ledger::REFUNDS_OWED, $refund->amount_sen, 0]];
        foreach ($refund->funding ?? [] as $account => $sen) {
            $lines[] = [$account, 0, $sen];
        }
        $this->ledger->post('refund_sent', 'Refund of '.$this->rm($refund->amount_sen)." sent to {$refund->order->buyer->name} for {$refund->order->reference}", $refund->order, $lines);

        $refund->update([
            'status' => $status === RefundResult::PENDING ? Refund::PENDING : Refund::SUCCEEDED,
            'gateway_reference' => $reference,
            'failure_reason' => null,
            'completed_at' => $status === RefundResult::PENDING ? null : now(),
        ]);
    }

    /**
     * Take refunded parts back from trainers who were already paid. Only for
     * deferred orders: there SkillMatch made the payout, so it can reverse it.
     * With instant split the gateway paid them and the part stays owed back.
     */
    public function collectClawbacks(Refund $refund): void
    {
        $refund = $refund->fresh(['order']);
        if ($refund->order->payout_mode !== PayoutMode::Deferred || $refund->outstandingClawbacks() === []) {
            return;
        }

        $provider = $this->gateways->payoutProvider($refund->order->gateway);
        if (! $provider instanceof ReversesPayouts) {
            return;
        }

        foreach ($refund->outstandingClawbacks() as $entry) {
            $split = OrderSplit::with('recipient', 'order')->findOrFail($entry['split_id']);
            $result = $provider->reversePayout($split, $entry['amount_sen'], "clawback-{$refund->order->reference}-{$refund->id}-{$split->id}");

            DB::transaction(function () use ($refund, $split, $entry, $result) {
                $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
                $allocation = $refund->allocation;
                $index = array_search($entry['split_id'], array_column($allocation, 'split_id'), true);

                if (! $result->success) {
                    $allocation[$index]['clawback_error'] = $result->message;
                    $refund->update(['allocation' => $allocation]);
                    Log::warning('Clawback failed', ['refund' => $refund->id, 'split' => $split->id, 'message' => $result->message]);

                    return;
                }

                $this->ledger->post('clawback', 'Took '.$this->rm($entry['amount_sen'])." back from {$split->recipient->name} for {$split->order->reference}", $split->order, [
                    [Ledger::GATEWAY_CLEARING, $entry['amount_sen'], 0],
                    [$this->ledger->receivable($split->recipient), 0, $entry['amount_sen']],
                ]);

                $allocation[$index]['reversal_reference'] = $result->reference;
                $allocation[$index]['clawback_error'] = null;
                $refund->update(['allocation' => $allocation]);

                if (! $this->hasOutstandingClawback($split)) {
                    $split->update(['status' => $split->remainingSen() === 0 ? SplitStatus::Reversed : SplitStatus::PaidOut]);
                }
            });
        }
    }

    // ---------------------------------------------------------------- helpers

    private function hasOutstandingClawback(OrderSplit $split): bool
    {
        return Refund::where('order_id', $split->order_id)->get()->contains(
            fn (Refund $r) => collect($r->outstandingClawbacks())->contains('split_id', $split->id),
        );
    }

    private function takeBackEarnedSkillpoints(Order $order): void
    {
        $earned = (int) $order->buyer->skillpointTransactions()
            ->where('order_id', $order->id)
            ->where('reason', 'like', 'Earned%')
            ->sum('points');

        if ($earned > 0) {
            $order->buyer->skillpointTransactions()->create([
                'order_id' => $order->id,
                'points' => -$earned,
                'reason' => "Taken back: order {$order->reference} was refunded",
            ]);
        }
    }

    private function paidPayment(Order $order): Payment
    {
        return $order->payments()->whereIn('status', ['paid', 'refunded'])->latest('id')->firstOrFail();
    }

    private function assertAmount(Order $order, int $amountSen): void
    {
        $max = $order->refundableSen();
        if ($amountSen < 1 || $amountSen > $max) {
            throw ValidationException::withMessages(['amount_sen' => 'The refund must be between RM 0.01 and '.$this->rm($max).'.']);
        }
    }

    private function assertPlatform(User $user): void
    {
        abort_unless($user->role === UserRole::Platform, 403, 'Only SkillMatch can do this.');
    }

    private function expectStatus(Refund $refund, string $status): void
    {
        if ($refund->status !== $status) {
            throw ValidationException::withMessages(['refund' => "This refund is {$refund->status}, not {$status}."]);
        }
    }

    private function rm(int $sen): string
    {
        return 'RM '.number_format($sen / 100, 2);
    }
}

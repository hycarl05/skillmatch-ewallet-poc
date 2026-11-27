<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PayoutMode;
use App\Enums\SplitRole;
use App\Enums\SplitStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderSplit;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Training;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\PayoutResult;
use App\Payments\ReportsProcessingFee;
use App\Payments\WebhookResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The whole order lifecycle:
 *
 *   pending_payment -> paid -> completed -> confirmed
 *                        \-------\------------\-> refunded  (see RefundService)
 *   pending_payment -> failed
 *   booked -> completed -> confirmed          (HRDC: paid by HRDC outside SkillMatch;
 *                                              the commission becomes owed by the provider)
 *
 * Money never touches SkillMatch's own account except its commission. Every
 * step writes a balanced ledger transaction describing where the money is.
 */
class OrderService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly Ledger $ledger,
        private readonly CommissionRules $commissionRules,
    ) {}

    /**
     * Work out the price breakdown for a training. Pure calculation, no writes.
     *
     * @return array<string, int>
     */
    public function quote(Training $training, User $buyer, int $requestedPoints = 0, string $funding = 'platform'): array
    {
        $price = $training->price_sen;

        // Commission and company discount come from the client's rule table
        // (buyer type, HRDC, subscription, amount). The discount is shared by
        // SkillMatch and the trainer exactly as the rule's percentages say.
        $rule = $this->commissionRules->for($buyer, $training);
        $amounts = $this->commissionRules->amounts($rule, $price);
        $fee = $amounts['platform_fee_sen'];
        $companyDiscount = $amounts['company_discount_sen'];

        $freelancerShare = $training->freelancer_id ? $training->freelancer_share_sen : 0;
        $sellerShare = $price - $fee - $companyDiscount - $freelancerShare;

        if ($sellerShare < 0) {
            throw ValidationException::withMessages(['training' => 'Freelancer share is larger than what is left after the platform fee.']);
        }

        // The SkillPoints discount comes out of SkillMatch's commission, so it
        // is capped at the fee and the trainer's share is never touched.
        // Nothing is paid through SkillMatch for an HRDC booking, so no points.
        $hrdc = $funding === 'hrdc';
        $senPerPoint = max(1, (int) config('skillmatch.skillpoints.sen_per_point'));
        $maxPoints = $hrdc ? 0 : min(intdiv($fee, $senPerPoint), max(0, $buyer->skillpointBalance()));
        $points = max(0, min($requestedPoints, $maxPoints));
        $discount = $points * $senPerPoint;

        return [
            'gross_sen' => $price,
            'funding' => $hrdc ? 'hrdc' : 'platform',
            'hrdc_claimable' => $training->hrdc_claimable,
            'commission_rule_id' => $rule?->id,
            'commission_rule_name' => $rule?->name ?? 'Default rate',
            'commission_bps' => $amounts['platform_bps'],
            'platform_fee_sen' => $fee,
            'company_discount_sen' => $companyDiscount,
            'seller_share_sen' => $sellerShare,
            'freelancer_share_sen' => $freelancerShare,
            'max_points' => $maxPoints,
            'points' => $points,
            'discount_sen' => $discount,
            // HRDC bookings: the buyer pays nothing through SkillMatch.
            'amount_sen' => $hrdc ? 0 : $price - $companyDiscount - $discount,
            'platform_net_sen' => $fee - $discount,
        ];
    }

    /** @return array{order: Order, checkout_url: string} */
    public function create(
        User $buyer,
        Training $training,
        ?User $beneficiary,
        int $points,
        string $gatewayName,
        ?PayoutMode $payoutMode = null,
        string $funding = 'platform',
    ): array {
        $this->validateBuyer($buyer, $training, $beneficiary);

        if ($funding === 'hrdc') {
            return ['order' => $this->bookHrdc($buyer, $training, $beneficiary, $points), 'checkout_url' => null];
        }

        if (! in_array($gatewayName, $this->gateways->available(), true)) {
            throw ValidationException::withMessages(['gateway' => "Unknown gateway '{$gatewayName}'."]);
        }

        $modes = $this->gateways->payoutModes($gatewayName);
        $payoutMode ??= $modes[0];
        if (! in_array($payoutMode, $modes, true)) {
            throw ValidationException::withMessages([
                'payout_mode' => "{$gatewayName} does not support {$payoutMode->value} payouts.",
            ]);
        }

        $quote = $this->quote($training, $buyer, $points);
        if ($points > $quote['max_points']) {
            throw ValidationException::withMessages([
                'skillpoints' => "You can use at most {$quote['max_points']} SkillPoints on this training.",
            ]);
        }

        [$order, $payment] = DB::transaction(function () use ($buyer, $training, $beneficiary, $gatewayName, $payoutMode, $quote) {
            $order = Order::create([
                'reference' => 'SM-'.Str::upper(Str::random(8)),
                'buyer_id' => $buyer->id,
                'beneficiary_id' => $beneficiary?->id,
                'training_id' => $training->id,
                'status' => OrderStatus::PendingPayment,
                'payout_mode' => $payoutMode,
                'gateway' => $gatewayName,
                'gross_sen' => $quote['gross_sen'],
                'skillpoints_used' => $quote['points'],
                'discount_sen' => $quote['discount_sen'],
                'company_discount_sen' => $quote['company_discount_sen'],
                'amount_sen' => $quote['amount_sen'],
                'platform_fee_sen' => $quote['platform_fee_sen'],
                'commission_rule_id' => $quote['commission_rule_id'],
                'commission_bps' => $quote['commission_bps'],
            ]);

            $seller = $training->seller;
            $this->addSplit($order, $seller, $seller->role === UserRole::Freelancer ? SplitRole::Freelancer : SplitRole::Provider, $quote['seller_share_sen']);
            if ($training->freelancer_id) {
                $this->addSplit($order, $training->freelancer, SplitRole::Freelancer, $quote['freelancer_share_sen']);
            }
            $this->addSplit($order, null, SplitRole::Platform, $quote['platform_net_sen']);

            if ($quote['points'] > 0) {
                $buyer->skillpointTransactions()->create([
                    'order_id' => $order->id,
                    'points' => -$quote['points'],
                    'reason' => "Used on order {$order->reference}",
                ]);
            }

            $payment = $order->payments()->create([
                'gateway' => $gatewayName,
                'amount_sen' => $quote['amount_sen'],
                'status' => 'pending',
            ]);

            return [$order, $payment];
        });

        // Call the gateway outside the DB transaction: never hold a lock while
        // waiting on a network call.
        try {
            $session = $this->gateways->gateway($gatewayName)->createCheckout(
                $order,
                $payment,
                route('webhooks.handle', ['gateway' => $gatewayName]),
                route('payment.return', ['payment' => $payment->id]),
            );
        } catch (Throwable $e) {
            Log::error('Gateway checkout failed', ['order' => $order->reference, 'error' => $e->getMessage()]);
            $this->markPaymentFailed($payment);

            throw ValidationException::withMessages(['gateway' => "Could not start payment with {$gatewayName}: {$e->getMessage()}"]);
        }

        $payment->update(['gateway_reference' => $session->reference, 'checkout_url' => $session->url]);

        return ['order' => $order->fresh(), 'checkout_url' => $session->url];
    }

    /**
     * An HRDC-claimable training booked "through HRDC": HRDC pays the provider
     * directly, so no checkout is created. SkillMatch's commission is booked
     * as owed by the provider once the training is confirmed, and kept back
     * from their next payout (Est Commission sheet, scenario #2).
     */
    private function bookHrdc(User $buyer, Training $training, ?User $beneficiary, int $points): Order
    {
        if (! $training->hrdc_claimable) {
            throw ValidationException::withMessages(['funding' => 'This training is not HRDC-claimable.']);
        }
        if ($points > 0) {
            throw ValidationException::withMessages(['skillpoints' => 'SkillPoints can only be used on trainings paid through SkillMatch.']);
        }

        $quote = $this->quote($training, $buyer, 0, 'hrdc');

        return DB::transaction(function () use ($buyer, $training, $beneficiary, $quote) {
            $order = Order::create([
                'reference' => 'SM-'.Str::upper(Str::random(8)),
                'buyer_id' => $buyer->id,
                'beneficiary_id' => $beneficiary?->id,
                'training_id' => $training->id,
                'status' => OrderStatus::Booked,
                'payout_mode' => PayoutMode::Deferred,
                'gateway' => 'hrdc',
                'funding' => 'hrdc',
                'gross_sen' => $quote['gross_sen'],
                'amount_sen' => 0,
                'platform_fee_sen' => $quote['platform_fee_sen'],
                'commission_rule_id' => $quote['commission_rule_id'],
                'commission_bps' => $quote['commission_bps'],
            ]);

            // Only SkillMatch's commission is tracked: the trainer's money
            // comes from HRDC, outside the platform.
            $this->addSplit($order, null, SplitRole::Platform, $quote['platform_fee_sen']);

            return $order->fresh();
        });
    }

    /** Apply a verified webhook. Safe to call more than once for the same event. */
    public function applyWebhook(string $gatewayName, WebhookResult $result): string
    {
        $payment = Payment::where('gateway', $gatewayName)->where('gateway_reference', $result->reference)->first();

        if (! $payment) {
            return 'unknown_payment';
        }

        return match ($result->status) {
            WebhookResult::PAID => $this->markPaid($payment, $result->amountSen, $result->gatewayPaymentId),
            WebhookResult::FAILED => $this->markPaymentFailed($payment),
            default => 'pending',
        };
    }

    public function markPaid(Payment $payment, ?int $paidAmountSen, ?string $gatewayPaymentId = null): string
    {
        $outcome = DB::transaction(function () use ($payment, $paidAmountSen, $gatewayPaymentId) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'paid') {
                return 'already_paid';
            }
            if ($payment->status !== 'pending') {
                return "ignored_{$payment->status}";
            }
            if ($paidAmountSen !== null && $paidAmountSen !== $payment->amount_sen) {
                Log::warning('Paid amount does not match', ['payment' => $payment->id, 'expected' => $payment->amount_sen, 'got' => $paidAmountSen]);

                return 'amount_mismatch';
            }

            $payment->update(['status' => 'paid', 'paid_at' => now(), 'gateway_payment_id' => $gatewayPaymentId ?? $payment->gateway_payment_id]);
            $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);

            $deferred = $order->payout_mode === PayoutMode::Deferred;
            $lines = [
                [Ledger::GATEWAY_CLEARING, $order->amount_sen, 0],
                [Ledger::SKILLPOINTS_DISCOUNT, $order->discount_sen, 0],
                [Ledger::PLATFORM_REVENUE, 0, $order->platform_fee_sen],
            ];
            foreach ($this->trainerSplits($order) as $split) {
                $account = $deferred ? $this->ledger->held($split->recipient) : $this->ledger->payable($split->recipient);
                $lines[] = [$account, 0, $split->amount_sen];
            }
            $this->ledger->post('payment', "Payment received for {$order->reference} via {$order->gateway}", $order, $lines);

            if ($deferred) {
                $order->splits()->update(['status' => SplitStatus::Held]);
            } else {
                // Billplz / ToyyibPay style: the gateway split the money at
                // collection, so every share is already with its owner.
                $this->settlePlatformShare($order);
                foreach ($this->trainerSplits($order) as $split) {
                    $this->recordPayout($split, "split-at-collection:{$payment->gateway_reference}");
                }
            }

            return 'paid';
        });

        return $outcome;
    }

    /**
     * Ask the gateway what it charged for processing and book it as
     * SkillMatch's expense: trainers get their full share, so the fee comes
     * out of SkillMatch's commission. Calls the gateway, so never run it
     * inside a DB transaction. Safe to call repeatedly; does nothing once known.
     */
    public function recordProcessingFee(Payment $payment): void
    {
        if ($payment->gateway_fee_sen !== null || ! in_array($payment->status, ['paid', 'refunded'], true)) {
            return;
        }

        $gateway = $this->gateways->gateway($payment->gateway);
        if (! $gateway instanceof ReportsProcessingFee) {
            return;
        }

        try {
            $fee = $gateway->processingFee($payment);
        } catch (Throwable $e) {
            Log::warning('Could not fetch processing fee', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($fee === null) {
            return;
        }

        DB::transaction(function () use ($payment, $fee) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->gateway_fee_sen !== null) {
                return;
            }

            $payment->update(['gateway_fee_sen' => $fee]);

            if ($fee > 0) {
                $order = $payment->order;
                $this->ledger->post('gateway_fee', ucfirst($payment->gateway)." processing fee for {$order->reference}, paid from SkillMatch's commission", $order, [
                    [Ledger::GATEWAY_FEES, $fee, 0],
                    [Ledger::PLATFORM_BANK, 0, $fee],
                ]);
            }
        });
    }

    public function markPaymentFailed(Payment $payment): string
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') {
                return "ignored_{$payment->status}";
            }

            $order = $payment->order;
            $payment->update(['status' => 'failed']);
            $order->update(['status' => OrderStatus::Failed]);
            $this->restoreSkillpoints($order, 'payment failed');

            return 'failed';
        });
    }

    /** The trainer says the training has been delivered. */
    public function complete(Order $order, User $by): Order
    {
        $isTrainer = $order->isSeller($by) || $order->training->freelancer_id === $by->id;
        abort_unless($isTrainer, 403, 'Only the trainer can mark this training as completed.');
        $this->expectStatus($order, $order->isHrdcFunded() ? OrderStatus::Booked : OrderStatus::Paid);

        $order->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);

        return $order;
    }

    /**
     * The buyer (or the auto-confirm job, $by = null) confirms the training.
     * In deferred mode this is when the trainers' money is released and paid out.
     */
    public function confirm(Order $order, ?User $by): Order
    {
        if ($by) {
            abort_unless($order->buyer_id === $by->id, 403, 'Only the buyer can confirm this training.');
        }
        $this->expectStatus($order, OrderStatus::Completed);

        DB::transaction(function () use ($order) {
            $order->update(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()]);

            if ($order->isHrdcFunded()) {
                $this->bookHrdcCommission($order);

                return;
            }

            if ($order->payout_mode === PayoutMode::Deferred) {
                // Only what's left after any refund is released.
                foreach ($this->trainerSplits($order)->where('status', SplitStatus::Held) as $split) {
                    $this->ledger->post('release', "Released {$split->role->value} share of {$order->reference}", $order, [
                        [$this->ledger->held($split->recipient), $split->remainingSen(), 0],
                        [$this->ledger->payable($split->recipient), 0, $split->remainingSen()],
                    ]);
                    $split->update(['status' => SplitStatus::Released]);
                }
                $this->settlePlatformShare($order);
            }

            $earned = intdiv($order->amount_sen, 100) * (int) config('skillmatch.skillpoints.earn_per_ringgit');
            if ($earned > 0) {
                $order->buyer->skillpointTransactions()->create([
                    'order_id' => $order->id,
                    'points' => $earned,
                    'reason' => "Earned from order {$order->reference}",
                ]);
            }
        });

        if (! $order->isHrdcFunded()) {
            $this->payOutReleased($order);
        }

        return $order->fresh();
    }

    /** After an HRDC training is confirmed, the provider owes SkillMatch its commission. */
    private function bookHrdcCommission(Order $order): void
    {
        $split = $order->splits()->where('role', SplitRole::Platform)->first();
        if (! $split) {
            return;
        }

        $provider = $order->training->seller;
        $this->ledger->post('hrdc_commission', "HRDC commission for {$order->reference}, owed by {$provider->name}", $order, [
            [$this->ledger->commissionDue($provider), $split->amount_sen, 0],
            [Ledger::PLATFORM_REVENUE, 0, $split->amount_sen],
        ]);
        $split->update(['status' => SplitStatus::Owed]);
    }

    /** Send every released split to its recipient. Safe to retry. Paused while the payment is disputed. */
    public function payOutReleased(Order $order): void
    {
        if ($order->hasOpenDispute()) {
            Log::notice('Payouts paused: payment is disputed', ['order' => $order->reference]);

            return;
        }

        $provider = $this->gateways->payoutProvider($order->gateway);

        foreach ($order->splits()->where('status', SplitStatus::Released)->whereNotNull('recipient_id')->with('recipient')->get() as $split) {
            // What this trainer owes SkillMatch is kept back from the payout:
            // refund fees first, then HRDC commission.
            $available = $split->remainingSen();
            $offset = min(max(0, $this->ledger->balance($this->ledger->feeDebt($split->recipient))), $available);
            $commissionOffset = min(max(0, $this->ledger->balance($this->ledger->commissionDue($split->recipient))), $available - $offset);
            $amount = $available - $offset - $commissionOffset;

            $result = $amount > 0 ? $provider->payout($split, $amount) : new PayoutResult(true, 'kept-for-amounts-owed');

            if (! $result->success) {
                Log::warning('Payout failed, will retry', ['split' => $split->id, 'message' => $result->message]);

                continue;
            }

            DB::transaction(fn () => $this->recordPayout($split, $result->reference, $offset, $commissionOffset));
        }
    }

    public function autoConfirmDue(): int
    {
        // An open refund request or dispute waits for SkillMatch to decide first.
        $due = Order::where('status', OrderStatus::Completed)
            ->where('completed_at', '<=', now()->subDays(config('skillmatch.auto_confirm_days')))
            ->whereDoesntHave('refunds', fn ($q) => $q->where('status', Refund::REQUESTED))
            ->whereDoesntHave('payments', fn ($q) => $q->where('dispute_status', 'open'))
            ->get();

        $due->each(fn (Order $order) => $this->confirm($order, null));

        return $due->count();
    }

    private function validateBuyer(User $buyer, Training $training, ?User $beneficiary): void
    {
        if (! $buyer->role->canBuy()) {
            throw ValidationException::withMessages(['buyer' => 'This account type cannot buy trainings.']);
        }
        if (in_array($buyer->id, [$training->seller_id, $training->freelancer_id], true)) {
            throw ValidationException::withMessages(['training' => 'You cannot buy your own training.']);
        }

        if ($buyer->role === UserRole::CompanyHr) {
            if (! $beneficiary || $beneficiary->company_id !== $buyer->id) {
                throw ValidationException::withMessages(['beneficiary_id' => 'Choose one of your staff for this training.']);
            }
        } elseif ($beneficiary) {
            throw ValidationException::withMessages(['beneficiary_id' => 'Only company HR can buy a training for someone else.']);
        }
    }

    private function addSplit(Order $order, ?User $recipient, SplitRole $role, int $amountSen): void
    {
        if ($amountSen <= 0) {
            return;
        }

        $order->splits()->create([
            'recipient_id' => $recipient?->id,
            'role' => $role,
            'amount_sen' => $amountSen,
            'status' => SplitStatus::Pending,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, OrderSplit> */
    private function trainerSplits(Order $order)
    {
        return $order->splits()->whereNotNull('recipient_id')->with('recipient')->get();
    }

    /** SkillMatch's own commission moves from the gateway to SkillMatch's bank. */
    private function settlePlatformShare(Order $order): void
    {
        $split = $order->splits()->where('role', SplitRole::Platform)->first();
        if (! $split || $split->status === SplitStatus::Reversed) {
            return;
        }

        $this->ledger->post('platform_settlement', "SkillMatch commission settled for {$order->reference}", $order, [
            [Ledger::PLATFORM_BANK, $split->remainingSen(), 0],
            [Ledger::GATEWAY_CLEARING, 0, $split->remainingSen()],
        ]);
        $split->update(['status' => SplitStatus::PaidOut, 'paid_out_at' => now()]);
    }

    /**
     * $offset: kept back to settle refund fees the recipient owed.
     * $commissionOffset: kept back to settle HRDC commission they owed.
     * Both go to SkillMatch's bank instead of the recipient.
     */
    private function recordPayout(OrderSplit $split, ?string $reference, int $offset = 0, int $commissionOffset = 0): void
    {
        $split->loadMissing('order', 'recipient');

        $description = "Paid {$split->recipient->name} for {$split->order->reference}";
        if ($offset > 0) {
            $description .= ', keeping RM '.number_format($offset / 100, 2).' for refund fees they owed';
        }
        if ($commissionOffset > 0) {
            $description .= ', keeping RM '.number_format($commissionOffset / 100, 2).' for HRDC commission they owed';
        }

        $this->ledger->post('payout', $description, $split->order, [
            [$this->ledger->payable($split->recipient), $split->remainingSen(), 0],
            [Ledger::PLATFORM_BANK, $offset + $commissionOffset, 0],
            [Ledger::GATEWAY_CLEARING, 0, $split->remainingSen()],
            [$this->ledger->feeDebt($split->recipient), 0, $offset],
            [$this->ledger->commissionDue($split->recipient), 0, $commissionOffset],
        ]);
        $split->update(['status' => SplitStatus::PaidOut, 'payout_reference' => $reference, 'payout_offset_sen' => $offset + $commissionOffset, 'paid_out_at' => now()]);

        // HRDC commission fully collected: those orders' commission is settled.
        if ($commissionOffset > 0 && $this->ledger->balance($this->ledger->commissionDue($split->recipient)) <= 0) {
            OrderSplit::where('role', SplitRole::Platform)
                ->where('status', SplitStatus::Owed)
                ->whereHas('order', fn ($q) => $q->where('funding', 'hrdc')->whereHas('training', fn ($t) => $t->where('seller_id', $split->recipient_id)))
                ->update(['status' => SplitStatus::PaidOut, 'paid_out_at' => now()]);
        }
    }

    public function restoreSkillpoints(Order $order, string $why): void
    {
        if ($order->skillpoints_used > 0) {
            $order->buyer->skillpointTransactions()->create([
                'order_id' => $order->id,
                'points' => $order->skillpoints_used,
                'reason' => "Returned from order {$order->reference} ({$why})",
            ]);
        }
    }

    private function expectStatus(Order $order, OrderStatus $status): void
    {
        if ($order->status !== $status) {
            throw ValidationException::withMessages([
                'order' => "Order is {$order->status->value}, expected {$status->value}.",
            ]);
        }
    }
}

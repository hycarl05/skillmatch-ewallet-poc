<?php

namespace App\Payments\Payouts;

use App\Models\OrderSplit;
use App\Models\Payment;
use App\Payments\PayoutProvider;
use App\Payments\PayoutResult;
use App\Payments\ReversesPayouts;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Moves a released split from SkillMatch's Stripe balance to the trainer's
 * Connect account. Stripe then pays it to their bank on the account's payout
 * schedule. After a refund, a transfer reversal pulls the trainer's part back.
 */
class StripeConnectPayoutProvider implements PayoutProvider, ReversesPayouts
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function payout(OrderSplit $split, int $amountSen): PayoutResult
    {
        $split->loadMissing('order', 'recipient');
        $accountId = $split->recipient?->stripe_account_id;

        if (! $accountId) {
            return new PayoutResult(false, message: "{$split->recipient?->name} has not set up Stripe payouts yet");
        }

        $params = [
            // What's left after refunds, minus any refund fees the trainer owes.
            'amount' => $amountSen,
            'currency' => 'myr',
            'destination' => $accountId,
            'transfer_group' => $split->order->reference,
            'metadata' => ['order_reference' => $split->order->reference, 'split_id' => (string) $split->id],
        ];

        try {
            // Tie the transfer to the buyer's charge so it can go out before
            // that charge's funds become available in SkillMatch's balance.
            if ($charge = $this->chargeFor($split)) {
                $params['source_transaction'] = $charge;
            }

            // Same key on every retry, so a split is never transferred twice.
            // The random order reference keeps it unique if split ids are reused.
            $transfer = $this->stripe->transfers->create($params, ['idempotency_key' => "payout-{$split->order->reference}-{$split->id}"]);
        } catch (ApiErrorException $e) {
            return new PayoutResult(false, message: 'Stripe transfer failed: '.$e->getMessage());
        }

        return new PayoutResult(true, $transfer->id);
    }

    /**
     * Fails when the trainer's Connect balance is too low (Stripe already paid
     * it to their bank). The share then stays "owed back" for SkillMatch to
     * collect another way.
     */
    public function reversePayout(OrderSplit $split, int $amountSen, string $idempotencyKey): PayoutResult
    {
        $split->loadMissing('order');

        if (! str_starts_with((string) $split->payout_reference, 'tr_')) {
            return new PayoutResult(false, message: 'This share was not paid by a Stripe transfer');
        }

        try {
            $reversal = $this->stripe->transfers->createReversal($split->payout_reference, [
                'amount' => $amountSen,
                'metadata' => ['order_reference' => $split->order->reference, 'split_id' => (string) $split->id],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $e) {
            return new PayoutResult(false, message: 'Stripe transfer reversal failed: '.$e->getMessage());
        }

        return new PayoutResult(true, $reversal->id);
    }

    private function chargeFor(OrderSplit $split): ?string
    {
        $payment = Payment::where('order_id', $split->order_id)
            ->where('gateway', 'stripe')
            ->where('status', 'paid')
            ->first();

        if (! $payment) {
            return null;
        }

        $session = $this->stripe->checkout->sessions->retrieve($payment->gateway_reference, [
            'expand' => ['payment_intent'],
        ]);

        return $session->payment_intent?->latest_charge;
    }
}

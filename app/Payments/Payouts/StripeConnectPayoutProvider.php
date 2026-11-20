<?php

namespace App\Payments\Payouts;

use App\Models\OrderSplit;
use App\Models\Payment;
use App\Payments\PayoutProvider;
use App\Payments\PayoutResult;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Moves a released split from SkillMatch's Stripe balance to the trainer's
 * Connect account. Stripe then pays it to their bank on the account's payout
 * schedule.
 */
class StripeConnectPayoutProvider implements PayoutProvider
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function payout(OrderSplit $split): PayoutResult
    {
        $split->loadMissing('order', 'recipient');
        $accountId = $split->recipient?->stripe_account_id;

        if (! $accountId) {
            return new PayoutResult(false, message: "{$split->recipient?->name} has not set up Stripe payouts yet");
        }

        $params = [
            'amount' => $split->amount_sen,
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

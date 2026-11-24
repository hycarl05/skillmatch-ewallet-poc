<?php

namespace App\Payments\Payouts;

use App\Models\OrderSplit;
use App\Payments\PayoutProvider;
use App\Payments\PayoutResult;
use App\Payments\ReversesPayouts;
use Illuminate\Support\Str;

/**
 * Pretends to send money to the recipient's bank, and to take it back after
 * a refund. A real implementation would call CHIP Send, Xendit payouts,
 * Stripe Connect transfers, etc.
 */
class FakePayoutProvider implements PayoutProvider, ReversesPayouts
{
    public function payout(OrderSplit $split, int $amountSen): PayoutResult
    {
        return new PayoutResult(true, 'fake_payout_'.Str::lower(Str::random(10)));
    }

    public function reversePayout(OrderSplit $split, int $amountSen, string $idempotencyKey): PayoutResult
    {
        return new PayoutResult(true, 'fake_reversal_'.Str::lower(Str::random(10)));
    }
}

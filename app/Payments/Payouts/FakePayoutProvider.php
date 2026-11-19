<?php

namespace App\Payments\Payouts;

use App\Models\OrderSplit;
use App\Payments\PayoutProvider;
use App\Payments\PayoutResult;
use Illuminate\Support\Str;

/**
 * Pretends to send money to the recipient's bank. A real implementation
 * would call CHIP Send, Xendit payouts, Stripe Connect transfers, etc.
 */
class FakePayoutProvider implements PayoutProvider
{
    public function payout(OrderSplit $split): PayoutResult
    {
        return new PayoutResult(true, 'fake_payout_'.Str::lower(Str::random(10)));
    }
}

<?php

namespace App\Payments;

use App\Models\OrderSplit;

/** Sends a released split to the recipient's bank account (deferred payout mode). */
interface PayoutProvider
{
    /** $amountSen can be less than the split: refund fees the recipient owes are kept back. */
    public function payout(OrderSplit $split, int $amountSen): PayoutResult;
}

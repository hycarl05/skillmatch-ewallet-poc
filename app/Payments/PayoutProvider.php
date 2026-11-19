<?php

namespace App\Payments;

use App\Models\OrderSplit;

/** Sends a released split to the recipient's bank account (deferred payout mode). */
interface PayoutProvider
{
    public function payout(OrderSplit $split): PayoutResult;
}

<?php

namespace App\Payments;

use App\Models\OrderSplit;

/**
 * A payout provider that can take money back from a recipient it already
 * paid (Stripe Connect transfer reversals). Used when an order is refunded
 * after the trainers were paid.
 */
interface ReversesPayouts
{
    public function reversePayout(OrderSplit $split, int $amountSen, string $idempotencyKey): PayoutResult;
}

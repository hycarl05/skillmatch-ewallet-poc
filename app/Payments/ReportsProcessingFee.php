<?php

namespace App\Payments;

use App\Models\Payment;

/** A gateway that can tell us what it charged for processing a payment. */
interface ReportsProcessingFee
{
    /** The fee in sen, or null if the gateway hasn't settled it yet (ask again later). */
    public function processingFee(Payment $payment): ?int;
}

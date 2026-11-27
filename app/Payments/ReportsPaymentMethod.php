<?php

namespace App\Payments;

use App\Models\Payment;

/** A gateway that can tell us how the buyer paid (needed for the FPX confirmation). */
interface ReportsPaymentMethod
{
    /** Null if the gateway has no charge for it yet (ask again later). */
    public function paymentMethod(Payment $payment): ?PaymentMethodUsed;
}

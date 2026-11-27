<?php

namespace App\Payments;

/** How a payment was actually made, as the gateway reports it after payment. */
final class PaymentMethodUsed
{
    /**
     * @param  array<string, string|null>  $details  e.g. FPX ['bank' => 'maybank2u', 'transaction_id' => '...'], card ['brand' => 'visa', 'last4' => '4242']
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $chargeId = null,
        public readonly array $details = [],
    ) {}
}

<?php

namespace App\Payments;

final class CheckoutSession
{
    public function __construct(
        public readonly string $reference, // bill / purchase id at the gateway
        public readonly string $url,       // where to send the buyer to pay
    ) {}
}

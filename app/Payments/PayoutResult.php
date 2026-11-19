<?php

namespace App\Payments;

final class PayoutResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
    ) {}
}

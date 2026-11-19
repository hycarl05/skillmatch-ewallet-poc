<?php

namespace App\Payments;

final class WebhookResult
{
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const PENDING = 'pending';

    public function __construct(
        public readonly string $reference,
        public readonly string $status,
        public readonly ?int $amountSen,
        public readonly string $eventKey, // unique per event, used to ignore duplicates
        public readonly array $payload,
    ) {}
}

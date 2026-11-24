<?php

namespace App\Payments;

final class WebhookResult
{
    // What the event is about.
    public const KIND_PAYMENT = 'payment';  // reference = the payment's gateway_reference
    public const KIND_REFUND = 'refund';    // reference = the refund's gateway_reference
    public const KIND_DISPUTE = 'dispute';  // reference = the dispute id, gatewayPaymentId = the disputed charge

    // Payment and refund statuses.
    public const PAID = 'paid';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const PENDING = 'pending';

    // Dispute statuses.
    public const DISPUTE_OPEN = 'open';
    public const DISPUTE_WON = 'won';
    public const DISPUTE_LOST = 'lost';

    public function __construct(
        public readonly string $reference,
        public readonly string $status,
        public readonly ?int $amountSen,
        public readonly string $eventKey, // unique per event, used to ignore duplicates
        public readonly array $payload,
        public readonly string $kind = self::KIND_PAYMENT,
        public readonly ?string $gatewayPaymentId = null,
        public readonly ?string $message = null, // refund failure or Stripe reason / dispute reason
        public readonly ?string $ownReference = null, // our refund id, when the gateway echoes it back in metadata
    ) {}
}

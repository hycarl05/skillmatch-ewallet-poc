<?php

namespace App\Payments;

final class WebhookResult
{
    // What the event is about.
    public const KIND_PAYMENT = 'payment';  // reference = the payment's gateway_reference
    public const KIND_REFUND = 'refund';    // reference = the refund's gateway_reference
    public const KIND_DISPUTE = 'dispute';  // reference = the dispute id, gatewayPaymentId = the disputed charge
    public const KIND_SUBSCRIPTION = 'subscription'; // see $subject

    // Payment and refund statuses.
    public const PAID = 'paid';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const PENDING = 'pending';
    public const CANCELLED = 'cancelled'; // a gateway subscription ended

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
        public readonly ?string $ownReference = null, // our refund / subscription / invoice id, when the gateway echoes it back in metadata
        // Subscriptions only. subject:
        //   invoice      - a SkillMatch renewal invoice paid at a checkout (reference = its gateway_reference)
        //   checkout     - a card subscription's checkout finished (gatewayPaymentId = the gateway's subscription id)
        //   renewal      - the gateway charged a card subscription for a period (reference = the gateway's invoice id)
        //   subscription - the gateway's subscription itself changed (status CANCELLED when it ended)
        public readonly ?string $subject = null,
        public readonly ?int $periodStart = null, // unix time, renewal only
        public readonly ?int $periodEnd = null,
    ) {}
}

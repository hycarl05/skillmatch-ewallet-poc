<?php

namespace App\Payments;

final class RefundResult
{
    public const SUCCEEDED = 'succeeded';
    public const PENDING = 'pending';                 // accepted, the gateway reports the outcome by webhook
    public const FAILED = 'failed';                   // rejected by the gateway; can be retried
    public const MANUAL_REQUIRED = 'manual_required'; // the gateway can't refund by API

    public function __construct(
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
    ) {}

    /** The gateway has the refund: the money is on its way back to the buyer. */
    public function accepted(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::PENDING], true);
    }
}

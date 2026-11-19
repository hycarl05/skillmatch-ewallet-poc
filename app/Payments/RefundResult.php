<?php

namespace App\Payments;

final class RefundResult
{
    public const SUCCEEDED = 'succeeded';
    public const MANUAL_REQUIRED = 'manual_required';

    public function __construct(
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
    ) {}
}

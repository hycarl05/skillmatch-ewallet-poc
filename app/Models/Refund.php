<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refund of (part of) an order's payment.
 *
 *   requested -> rejected
 *   requested -> processing -> pending -> succeeded
 *                           \-> succeeded       (gateway refunded at once)
 *                           \-> failed          (retry from the admin page)
 *                           \-> manual_required (finish in the gateway dashboard, then mark as sent)
 *
 * A refund the admin starts skips "requested". A lost chargeback is booked
 * straight as "succeeded": the card network already took the money.
 */
class Refund extends Model
{
    public const REQUESTED = 'requested';
    public const REJECTED = 'rejected';
    public const PROCESSING = 'processing';
    public const PENDING = 'pending';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const MANUAL_REQUIRED = 'manual_required';

    /** Statuses whose amount is committed to the buyer (counts against what can still be refunded). */
    public const COMMITTED = [self::PROCESSING, self::PENDING, self::SUCCEEDED, self::FAILED, self::MANUAL_REQUIRED];

    /** The buyer hasn't got the money yet: SkillMatch owes it to them. */
    public const OWED = [self::FAILED, self::MANUAL_REQUIRED];

    // Who pays the refund's share of the gateway's processing fee.
    public const FEE_BUYER = 'buyer';
    public const FEE_TRAINER = 'trainer';
    public const FEE_SKILLMATCH = 'skillmatch';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'payment_id' => 'integer',
            'requested_by' => 'integer',
            'reviewed_by' => 'integer',
            'amount_sen' => 'integer', // what the buyer gets
            'gross_sen' => 'integer',  // the part of the payment refunded
            'fee_sen' => 'integer',
            'attempts' => 'integer',
            'allocation' => 'array',
            'funding' => 'array',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Shares still owed back by a trainer who had already been paid. */
    public function outstandingClawbacks(): array
    {
        return array_values(array_filter($this->allocation ?? [], fn ($a) => ($a['clawback'] ?? false) && empty($a['reversal_reference'])));
    }
}

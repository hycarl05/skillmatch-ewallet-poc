<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    public const PENDING = 'pending';     // waiting for the first payment
    public const ACTIVE = 'active';
    public const PAST_DUE = 'past_due';   // renewal not paid; features stay on until the grace period ends
    public const CANCELLED = 'cancelled'; // ended at the end of a paid period, on request
    public const EXPIRED = 'expired';     // ended because a renewal was never paid

    public const COLLECTION_CARD = 'card';       // the gateway charges the saved card each period (Stripe Billing)
    public const COLLECTION_INVOICE = 'invoice'; // an invoice each period, paid by FPX or card (FPX can't recur)

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'plan_id' => 'integer',
            'price_sen' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /** Does the plan's access apply right now? */
    public function grantsAccess(): bool
    {
        return match ($this->status) {
            self::ACTIVE => true,
            self::PAST_DUE => $this->grace_ends_at?->isFuture() ?? false,
            default => false,
        };
    }

    /** Stripe renews card subscriptions itself; everything else is renewed by SkillMatch's invoices. */
    public function renewsAtGateway(): bool
    {
        return $this->gateway === 'stripe' && $this->collection === self::COLLECTION_CARD;
    }
}

<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PayoutMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'buyer_id' => 'integer',
            'beneficiary_id' => 'integer',
            'training_id' => 'integer',
            'status' => OrderStatus::class,
            'payout_mode' => PayoutMode::class,
            'gross_sen' => 'integer',
            'skillpoints_used' => 'integer',
            'discount_sen' => 'integer',
            'amount_sen' => 'integer',
            'platform_fee_sen' => 'integer',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(OrderSplit::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class);
    }

    /** Money given back to the buyer, or committed to be (failed / manual refunds are still owed). */
    public function refundedSen(): int
    {
        return (int) $this->refunds()->whereIn('status', Refund::COMMITTED)->sum('amount_sen');
    }

    /** What part of the payment can still be refunded. A fee kept from a refund counts as refunded. */
    public function refundableSen(): int
    {
        return max(0, $this->amount_sen - (int) $this->refunds()->whereIn('status', Refund::COMMITTED)->sum('gross_sen'));
    }

    /** Processing fees on refunds that the buyer or trainer paid instead of SkillMatch. */
    public function refundFeesRecoveredSen(): int
    {
        return (int) $this->refunds()->whereIn('status', Refund::COMMITTED)->whereIn('fee_paid_by', [Refund::FEE_BUYER, Refund::FEE_TRAINER])->sum('fee_sen');
    }

    public function hasOpenDispute(): bool
    {
        return $this->payments()->where('dispute_status', 'open')->exists();
    }

    public function isSeller(User $user): bool
    {
        return $this->training->seller_id === $user->id;
    }

    public function involves(User $user): bool
    {
        return $this->buyer_id === $user->id
            || $this->beneficiary_id === $user->id
            || $this->splits->contains('recipient_id', $user->id);
    }
}

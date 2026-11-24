<?php

namespace App\Models;

use App\Enums\SplitRole;
use App\Enums\SplitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSplit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'recipient_id' => 'integer',
            'role' => SplitRole::class,
            'status' => SplitStatus::class,
            'amount_sen' => 'integer',
            'refunded_sen' => 'integer',
            'payout_offset_sen' => 'integer',
            'paid_out_at' => 'datetime',
        ];
    }

    /** The share after refunds: what the recipient keeps (or is still owed). */
    public function remainingSen(): int
    {
        return $this->amount_sen - $this->refunded_sen;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}

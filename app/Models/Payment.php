<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'amount_sen' => 'integer',
            'gateway_fee_sen' => 'integer',
            'dispute_amount_sen' => 'integer',
            'payment_method_details' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function hasOpenDispute(): bool
    {
        return $this->dispute_status === 'open';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

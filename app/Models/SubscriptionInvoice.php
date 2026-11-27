<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    public const OPEN = 'open';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const VOID = 'void';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subscription_id' => 'integer',
            'amount_sen' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}

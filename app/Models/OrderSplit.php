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
            'paid_out_at' => 'datetime',
        ];
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

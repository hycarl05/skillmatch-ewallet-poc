<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the client's commission table. Rates are basis points of the
 * training price (2000 = 20%), so they stay exact integers.
 */
class CommissionRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hrdc' => 'boolean',
            'requires_subscription' => 'boolean',
            'min_amount_sen' => 'integer',
            'platform_bps' => 'integer',
            'buyer_discount_bps' => 'integer',
            'priority' => 'integer',
            'active' => 'boolean',
            'effective_from' => 'date',
        ];
    }
}

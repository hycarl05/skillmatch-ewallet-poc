<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Demand Marketplace post: someone asking for a training. */
class TrainingRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['requester_id' => 'integer', 'budget_sen' => 'integer', 'participants' => 'integer'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}

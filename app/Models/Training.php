<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Training extends Model
{
    protected $fillable = ['seller_id', 'title', 'description', 'price_sen', 'freelancer_id', 'freelancer_share_sen'];

    protected function casts(): array
    {
        return [
            'seller_id' => 'integer',
            'freelancer_id' => 'integer',
            'price_sen' => 'integer',
            'freelancer_share_sen' => 'integer',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }
}

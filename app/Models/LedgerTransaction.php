<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerTransaction extends Model
{
    protected $guarded = [];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}

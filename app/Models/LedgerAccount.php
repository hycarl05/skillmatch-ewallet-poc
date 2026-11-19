<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    protected $guarded = [];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Balance in the account's natural direction: assets and expenses grow with
     * debits; liabilities and revenue grow with credits.
     */
    public function balanceSen(): int
    {
        $debits = (int) $this->entries()->sum('debit_sen');
        $credits = (int) $this->entries()->sum('credit_sen');

        return in_array($this->type, ['asset', 'expense'], true) ? $debits - $credits : $credits - $debits;
    }
}

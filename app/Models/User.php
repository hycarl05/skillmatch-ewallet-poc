<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'organisation', 'company_id', 'bank_name', 'bank_account_last4', 'stripe_account_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'company_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(User::class, 'company_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class, 'company_id');
    }

    public function skillpointTransactions(): HasMany
    {
        return $this->hasMany(SkillpointTransaction::class);
    }

    public function skillpointBalance(): int
    {
        return (int) $this->skillpointTransactions()->sum('points');
    }
}

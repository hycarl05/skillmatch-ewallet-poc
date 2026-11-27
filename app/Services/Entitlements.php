<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\User;

/**
 * The one place that answers "what can this user do?". Features come from
 * the user's plan when a subscription grants access, otherwise from the free
 * level of their user type (Tier Plan sheet, FOC column).
 */
class Entitlements
{
    /** Free level per user type. A plan's features override these keys. */
    private const FREE = [
        'individual' => ['demand_marketplace' => 'none', 'can_list_hrdc' => false, 'career_path' => false],
        'company_hr' => ['demand_marketplace' => 'none', 'can_list_hrdc' => false, 'career_path' => false],
        'provider' => ['demand_marketplace' => 'none', 'can_list_hrdc' => true, 'career_path' => false],
        'freelancer' => ['demand_marketplace' => 'none', 'can_list_hrdc' => false, 'career_path' => false],
    ];

    /** The subscription whose plan applies right now, if any. */
    public function activeSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->with('plan')
            ->whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])
            ->latest('id')
            ->get()
            ->first(fn (Subscription $s) => $s->grantsAccess());
    }

    /** @return array<string, mixed> */
    public function features(User $user): array
    {
        $free = self::FREE[$user->role->value] ?? ['demand_marketplace' => 'none', 'can_list_hrdc' => false, 'career_path' => false];
        $plan = $this->activeSubscription($user)?->plan;

        return array_merge($free, $plan?->features ?? []);
    }

    public function feature(User $user, string $key): mixed
    {
        return $this->features($user)[$key] ?? null;
    }

    /** Which Demand Marketplace requests a seller may see: none | state | all. */
    public function demandMarketplaceScope(User $user): string
    {
        if ($user->role === UserRole::Platform) {
            return 'all';
        }

        return (string) ($this->feature($user, 'demand_marketplace') ?? 'none');
    }

    public function hasActiveSubscription(User $user): bool
    {
        return $this->activeSubscription($user) !== null;
    }
}

<?php

namespace App\Services;

use App\Models\CommissionRule;
use App\Models\Training;
use App\Models\User;

/**
 * Picks the commission rule for an order. Candidates must match the buyer's
 * type (or any), the training's HRDC status (or both) and the price; rules
 * that need a subscription only count when the buyer has one. Highest
 * priority wins. No rule at all falls back to the flat config rate.
 */
class CommissionRules
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function for(User $buyer, Training $training): ?CommissionRule
    {
        $subscribed = null; // looked up only if a rule needs it

        return CommissionRule::query()
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('buyer_role')->orWhere('buyer_role', $buyer->role->value))
            ->where(fn ($q) => $q->whereNull('hrdc')->orWhere('hrdc', $training->hrdc_claimable))
            ->where('min_amount_sen', '<=', $training->price_sen)
            ->orderByDesc('priority')
            ->orderByDesc('min_amount_sen')
            ->get()
            ->first(function (CommissionRule $rule) use ($buyer, &$subscribed) {
                if (! $rule->requires_subscription) {
                    return true;
                }

                return $subscribed ??= $this->entitlements->hasActiveSubscription($buyer);
            });
    }

    /** The platform's share and the buyer's discount for a price, in sen. */
    public function amounts(?CommissionRule $rule, int $priceSen): array
    {
        $platformBps = $rule?->platform_bps ?? (int) round(config('skillmatch.platform_fee_percent') * 100);
        $discountBps = $rule?->buyer_discount_bps ?? 0;

        return [
            'platform_bps' => $platformBps,
            'platform_fee_sen' => intdiv($priceSen * $platformBps + 5000, 10000),
            'company_discount_sen' => intdiv($priceSen * $discountBps + 5000, 10000),
        ];
    }
}

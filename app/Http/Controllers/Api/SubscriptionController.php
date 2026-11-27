<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Entitlements;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** The Plans page: plans for my account type, my current plan, its invoices and what I can do now. */
    public function index(Request $request, Entitlements $entitlements): JsonResponse
    {
        $user = $request->user();
        $current = $user->subscriptions()->with('plan')->whereIn('status', [Subscription::PENDING, Subscription::ACTIVE, Subscription::PAST_DUE])->latest('id')->first();

        return response()->json([
            'plans' => $this->subscriptions->plansFor($user)->map(fn (Plan $p) => self::plan($p))->values(),
            'subscription' => $current ? self::subscription($current) : null,
            'features' => $entitlements->features($user),
            'staff_count' => $user->staff()->count(),
            'invoices' => SubscriptionInvoice::whereHas('subscription', fn ($q) => $q->where('user_id', $user->id))
                ->with('subscription.plan')->latest('id')->limit(24)->get()->map(fn (SubscriptionInvoice $i) => self::invoice($i))->values(),
            'gateways' => config('skillmatch.subscriptions.gateways'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'gateway' => ['required', 'string'],
            'collection' => ['required', Rule::in([Subscription::COLLECTION_CARD, Subscription::COLLECTION_INVOICE])],
        ]);

        $result = $this->subscriptions->subscribe($request->user(), Plan::findOrFail($data['plan_id']), $data['gateway'], $data['collection']);

        return response()->json([
            'subscription' => self::subscription($result['subscription']),
            'checkout_url' => $result['checkout_url'],
        ], 201);
    }

    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        return response()->json(self::subscription($this->subscriptions->cancel($subscription, $request->user())->load('plan')));
    }

    public static function plan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'audience' => $plan->audience,
            'tier' => $plan->tier,
            'price_sen' => $plan->price_sen,
            'interval' => $plan->interval,
            'min_staff' => $plan->min_staff,
            'max_staff' => $plan->max_staff,
            'features' => $plan->features,
        ];
    }

    public static function subscription(Subscription $subscription): array
    {
        $open = $subscription->invoices()->where('status', SubscriptionInvoice::OPEN)->latest('id')->first();

        return [
            'id' => $subscription->id,
            'plan' => self::plan($subscription->plan),
            'status' => $subscription->status,
            'gateway' => $subscription->gateway,
            'collection' => $subscription->collection,
            'price_sen' => $subscription->price_sen,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end,
            'grace_ends_at' => $subscription->grace_ends_at,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'grants_access' => $subscription->grantsAccess(),
            'renews_at_gateway' => $subscription->renewsAtGateway(),
            // Invoice-collected plans: the link to pay the next (or first) period.
            'pay_url' => $open?->checkout_url,
        ];
    }

    public static function invoice(SubscriptionInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'plan' => $invoice->subscription->plan->name,
            'amount_sen' => $invoice->amount_sen,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end,
            'status' => $invoice->status,
            'gateway' => $invoice->gateway,
            'paid_at' => $invoice->paid_at,
            'pay_url' => $invoice->status === SubscriptionInvoice::OPEN ? $invoice->checkout_url : null,
        ];
    }
}

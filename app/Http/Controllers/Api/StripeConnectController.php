<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\StripeClient;

/**
 * Trainers connect a Stripe Express account so their shares can be paid out.
 * Stripe hosts the onboarding (identity and bank details); SkillMatch only
 * keeps the account id.
 */
class StripeConnectController extends Controller
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role->sellsTraining(), 403, 'Only trainers receive payouts.');

        if (! $user->stripe_account_id) {
            return response()->json(['connected' => false]);
        }

        $account = $this->stripe->accounts->retrieve($user->stripe_account_id);

        return response()->json([
            'connected' => true,
            'account_id' => $account->id,
            'details_submitted' => (bool) $account->details_submitted,
            'payouts_enabled' => (bool) $account->payouts_enabled,
            'requirements_due' => $account->requirements?->currently_due ?? [],
        ]);
    }

    /** Create the Express account if needed and return a one-time onboarding link. */
    public function onboard(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role->sellsTraining(), 403, 'Only trainers receive payouts.');

        if (! $user->stripe_account_id) {
            $account = $this->stripe->accounts->create([
                'type' => 'express',
                'country' => 'MY',
                'email' => $user->email,
                'business_type' => $user->role->value === 'provider' ? 'company' : 'individual',
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'metadata' => ['skillmatch_user_id' => (string) $user->id],
            ], ['idempotency_key' => "connect-account-user-{$user->id}"]);

            $user->update(['stripe_account_id' => $account->id]);
        }

        // Account links expire after a few minutes and can be used once,
        // so a fresh one is made every time.
        $link = $this->stripe->accountLinks->create([
            'account' => $user->stripe_account_id,
            'type' => 'account_onboarding',
            'refresh_url' => url('/wallet?stripe=refresh'),
            'return_url' => url('/wallet?stripe=return'),
        ]);

        return response()->json(['url' => $link->url]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\TrainingRequest;
use App\Services\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Demand Marketplace: individuals and companies post the training they need;
 * providers and freelancers with a plan see them (Tier 1: their state, Tier 2: all states).
 */
class TrainingRequestController extends Controller
{
    public function index(Request $request, Entitlements $entitlements): JsonResponse
    {
        $user = $request->user();
        $query = TrainingRequest::with('requester')->where('status', 'open')->latest('id');

        if (in_array($user->role, [UserRole::Individual, UserRole::CompanyHr], true)) {
            return response()->json(['scope' => 'mine', 'requests' => $query->where('requester_id', $user->id)->get()->map(fn ($r) => self::present($r, true))]);
        }

        $scope = $entitlements->demandMarketplaceScope($user);

        if ($scope === 'none') {
            return response()->json([
                'scope' => 'none',
                'requests' => [],
                'upgrade' => 'Subscribe to Tier 1 to see training requests in your state, or Tier 2 for every state.',
                'hidden_count' => TrainingRequest::where('status', 'open')->count(),
            ]);
        }

        if ($scope === 'state') {
            $query->where('state', $user->state);
        }

        return response()->json([
            'scope' => $scope,
            'state' => $user->state,
            'requests' => $query->get()->map(fn ($r) => self::present($r, $user->role === UserRole::Platform)),
            // Tier 1 sellers see how many more are waiting in other states.
            'hidden_count' => $scope === 'state' ? TrainingRequest::where('status', 'open')->where('state', '!=', (string) $user->state)->count() : 0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Individual, UserRole::CompanyHr], true), 403, 'Only individuals and companies can post training requests.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'state' => ['required', 'string', 'max:50'],
            'budget_sen' => ['nullable', 'integer', 'min:0'],
            'participants' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $trainingRequest = TrainingRequest::create($data + ['requester_id' => $user->id, 'participants' => $data['participants'] ?? 1]);

        return response()->json(self::present($trainingRequest->load('requester'), true), 201);
    }

    private static function present(TrainingRequest $r, bool $withContact): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'description' => $r->description,
            'state' => $r->state,
            'budget_sen' => $r->budget_sen,
            'participants' => $r->participants,
            'requester' => $withContact ? Present::user($r->requester) : ['name' => $r->requester->name, 'role' => $r->requester->role->value],
            'created_at' => $r->created_at,
        ];
    }
}

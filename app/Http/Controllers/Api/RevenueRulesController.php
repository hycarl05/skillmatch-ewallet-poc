<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SkillMatch admin: the commission table and the plans currently in force. */
class RevenueRulesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Platform, 403);

        return response()->json([
            'fallback_percent' => config('skillmatch.platform_fee_percent'),
            'commission_rules' => CommissionRule::orderByDesc('priority')->get(),
            'plans' => Plan::orderBy('audience')->orderBy('tier')->get()->map(fn (Plan $p) => SubscriptionController::plan($p) + ['active' => $p->active]),
        ]);
    }
}

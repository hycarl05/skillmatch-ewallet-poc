<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\User;
use App\Payments\GatewayManager;
use Illuminate\Http\JsonResponse;

class DemoController extends Controller
{
    /** Demo accounts for the role switcher (PoC only). */
    public function users(): JsonResponse
    {
        abort_if(app()->isProduction(), 404);

        return response()->json(User::orderBy('id')->get()->map(fn ($u) => Present::user($u)));
    }

    public function config(GatewayManager $gateways): JsonResponse
    {
        return response()->json([
            'platform_fee_percent' => config('skillmatch.platform_fee_percent'),
            'sen_per_point' => config('skillmatch.skillpoints.sen_per_point'),
            'auto_confirm_days' => config('skillmatch.auto_confirm_days'),
            'default_gateway' => config('skillmatch.default_gateway'),
            'gateways' => collect($gateways->available())->map(fn ($name) => [
                'name' => $name,
                'payout_modes' => array_map(fn ($m) => $m->value, $gateways->payoutModes($name)),
            ])->values(),
        ]);
    }
}

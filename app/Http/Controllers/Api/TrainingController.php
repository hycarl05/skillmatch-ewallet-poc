<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Training;
use App\Services\Entitlements;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TrainingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Training::with('seller', 'freelancer')->orderBy('id')->get()->map(fn ($t) => Present::training($t)),
        );
    }

    /** A provider or freelancer lists a training. HRDC programmes need a plan that allows them. */
    public function store(Request $request, Entitlements $entitlements): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role->sellsTraining(), 403, 'Only training providers and freelance trainers can list trainings.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_sen' => ['required', 'integer', 'min:100'],
            'hrdc_claimable' => ['boolean'],
        ]);

        if (($data['hrdc_claimable'] ?? false) && ! $entitlements->feature($user, 'can_list_hrdc')) {
            throw ValidationException::withMessages([
                'hrdc_claimable' => 'Listing HRDC programmes needs a Freelance Trainer Tier 1 plan or higher.',
            ]);
        }

        $training = Training::create($data + ['seller_id' => $user->id]);

        return response()->json(Present::training($training->load('seller', 'freelancer')), 201);
    }

    /** Price breakdown for the current user, including how many SkillPoints they can use. */
    public function quote(Request $request, Training $training, OrderService $orders): JsonResponse
    {
        $funding = $request->query('funding') === 'hrdc' && $training->hrdc_claimable ? 'hrdc' : 'platform';
        $quote = $orders->quote($training, $request->user(), (int) $request->query('skillpoints', 0), $funding);

        // The buyer only needs what they will pay; how it's split stays internal.
        $data = collect($quote)->only(['gross_sen', 'max_points', 'points', 'discount_sen', 'company_discount_sen', 'amount_sen', 'funding', 'hrdc_claimable'])->all();
        $data['company_discount_label'] = $quote['company_discount_sen'] > 0 ? $quote['commission_rule_name'] : null;

        return response()->json($data);
    }
}

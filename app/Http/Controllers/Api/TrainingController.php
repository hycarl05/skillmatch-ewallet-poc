<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Training;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Training::with('seller', 'freelancer')->orderBy('id')->get()->map(fn ($t) => Present::training($t)),
        );
    }

    /** Price breakdown for the current user, including how many SkillPoints they can use. */
    public function quote(Request $request, Training $training, OrderService $orders): JsonResponse
    {
        return response()->json($orders->quote($training, $request->user(), (int) $request->query('skillpoints', 0)));
    }
}

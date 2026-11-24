<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Order;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    /** Buyer asks for a refund. Leave amount_sen out to ask for everything still refundable. */
    public function request(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'amount_sen' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->refunds->request($order, $request->user(), $data['amount_sen'] ?? null, $data['reason']);

        return $this->order($request, $order);
    }

    /** SkillMatch refunds directly. */
    public function store(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'amount_sen' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'fee_paid_by' => ['nullable', Rule::in(config('skillmatch.refunds.fee_paid_by'))],
        ]);

        $this->refunds->refundNow($order, $request->user(), $data['amount_sen'], $data['reason'], $data['fee_paid_by'] ?? Refund::FEE_SKILLMATCH);

        return $this->order($request, $order);
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $data = $request->validate([
            'amount_sen' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'fee_paid_by' => ['nullable', Rule::in(config('skillmatch.refunds.fee_paid_by'))],
        ]);

        $this->refunds->approve($refund, $request->user(), $data['amount_sen'] ?? null, $data['note'] ?? null, $data['fee_paid_by'] ?? Refund::FEE_BUYER);

        return $this->order($request, $refund->order);
    }

    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);

        $this->refunds->reject($refund, $request->user(), $data['note']);

        return $this->order($request, $refund->order);
    }

    public function retry(Request $request, Refund $refund): JsonResponse
    {
        $this->refunds->retry($refund, $request->user());

        return $this->order($request, $refund->order);
    }

    public function markSent(Request $request, Refund $refund): JsonResponse
    {
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:255']]);

        $this->refunds->markSent($refund, $request->user(), $data['reference'] ?? null);

        return $this->order($request, $refund->order);
    }

    /** SkillMatch's to-do list: requests to decide, refunds that didn't go out, money to take back. */
    public function queue(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Platform, 403);

        $refunds = Refund::with('order.buyer', 'order.training', 'requester')
            ->whereIn('status', [Refund::REQUESTED, Refund::FAILED, Refund::MANUAL_REQUIRED, Refund::PENDING, Refund::SUCCEEDED])
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (Refund $r) => ! in_array($r->status, [Refund::SUCCEEDED, Refund::PENDING], true) || $r->outstandingClawbacks() !== [])
            ->values()
            ->map(fn (Refund $r) => Present::refund($r, 'platform') + [
                'order' => ['id' => $r->order->id, 'reference' => $r->order->reference, 'training' => $r->order->training->title, 'buyer' => $r->order->buyer->name],
            ]);

        return response()->json($refunds);
    }

    private function order(Request $request, Order $order): JsonResponse
    {
        return response()->json(Present::order($order->fresh(), $request->user(), true));
    }
}

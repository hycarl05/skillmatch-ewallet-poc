<?php

namespace App\Http\Controllers\Api;

use App\Enums\SplitRole;
use App\Enums\SplitStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderSplit;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Days are bucketed in Malaysian time, so an order paid at 1am MYT lands on the right day. */
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    public const RANGES = [7, 30, 90, 365];

    /**
     * Admin monitoring: one row per order in the period (the page groups and
     * drills down on them) plus the things waiting for SkillMatch.
     */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Platform, 403);

        $days = in_array((int) $request->query('days'), self::RANGES, true) ? (int) $request->query('days') : 30;
        $to = now(self::TIMEZONE)->endOfDay();
        $from = $to->copy()->subDays($days - 1)->startOfDay();

        $orders = Order::query()
            ->with(['training.seller', 'buyer', 'splits', 'refunds', 'payments'])
            ->where(fn ($q) => $q
                ->whereBetween('paid_at', [$from->copy()->utc(), $to->copy()->utc()])
                ->orWhere(fn ($q) => $q->whereNull('paid_at')->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])))
            ->orderByDesc('id')
            ->get()
            ->map(function (Order $order) {
                $at = ($order->paid_at ?? $order->created_at)->timezone(self::TIMEZONE);
                $committed = $order->refunds->whereIn('status', Refund::COMMITTED);

                return [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'at' => $at->toIso8601String(),
                    'day' => $at->toDateString(),
                    'status' => $order->status->value,
                    'gateway' => $order->gateway,
                    'payout_mode' => $order->payout_mode->value,
                    'training' => $order->training->title,
                    'seller' => $order->training->seller->name,
                    'buyer' => $order->buyer->name,
                    'paid' => $order->paid_at !== null,
                    'amount_sen' => $order->paid_at ? $order->amount_sen : 0,
                    'refunded_sen' => (int) $committed->sum('amount_sen'),
                    'commission_sen' => (int) $order->splits->where('role', SplitRole::Platform)->sum(fn ($s) => $s->remainingSen()),
                    'gateway_fee_sen' => (int) $order->payments->sum('gateway_fee_sen'),
                ];
            });

        $owed = Refund::whereIn('status', Refund::OWED);

        return response()->json([
            'range' => ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'orders' => $orders,
            'attention' => [
                'refund_requests' => Refund::where('status', Refund::REQUESTED)->count(),
                'refunds_owed' => $owed->count(),
                'refunds_owed_sen' => (int) $owed->sum('amount_sen'),
                'clawbacks' => OrderSplit::where('status', SplitStatus::ClawbackRequired)->count(),
                'open_disputes' => Payment::where('dispute_status', 'open')->count(),
                'held_sen' => (int) OrderSplit::where('status', SplitStatus::Held)->get()->sum(fn ($s) => $s->remainingSen()),
                'books_balanced' => (int) LedgerEntry::sum('debit_sen') === (int) LedgerEntry::sum('credit_sen'),
            ],
        ]);
    }
}

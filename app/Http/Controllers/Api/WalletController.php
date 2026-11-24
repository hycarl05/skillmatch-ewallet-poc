<?php

namespace App\Http\Controllers\Api;

use App\Enums\SplitStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\LedgerEntry;
use App\Models\OrderSplit;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The "e-wallet" a user sees. It's a view over the ledger: SkillMatch
 * doesn't keep a balance column, and it never holds the money itself.
 */
class WalletController extends Controller
{
    public function show(Request $request, Ledger $ledger): JsonResponse
    {
        $user = $request->user();
        $codes = [$ledger->held($user), $ledger->payable($user), $ledger->receivable($user), $ledger->feeDebt($user)];

        $entries = LedgerEntry::query()
            ->whereHas('account', fn ($q) => $q->whereIn('code', $codes))
            ->with('account', 'transaction')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (LedgerEntry $e) => Present::entry($e) + [
                'id' => $e->id,
                'type' => $e->transaction->type,
                'description' => $e->transaction->description,
                'order_id' => $e->transaction->order_id,
                'created_at' => $e->created_at,
            ]);

        return response()->json([
            'user' => Present::user($user),
            'skillpoints' => [
                'balance' => $user->skillpointBalance(),
                'history' => $user->skillpointTransactions()->latest('id')->limit(50)->get(['id', 'points', 'reason', 'order_id', 'created_at']),
            ],
            'earnings' => [
                'held_sen' => $ledger->balance($codes[0]),
                'releasing_sen' => $ledger->balance($codes[1]),
                'paid_out_sen' => (int) OrderSplit::where('recipient_id', $user->id)->where('status', SplitStatus::PaidOut)->sum(DB::raw('amount_sen - refunded_sen')),
                // Shares refunded after they were paid, plus refund fees charged to them.
                'owed_back_sen' => $ledger->balance($codes[2]) + $ledger->balance($codes[3]),
                'entries' => $entries,
            ],
            'spending' => [
                'paid_sen' => (int) Payment::whereIn('status', ['paid', 'refunded'])->whereHas('order', fn ($q) => $q->where('buyer_id', $user->id))->sum('amount_sen'),
                // Only what has actually been sent back, not refunds still waiting or failed.
                'refunded_sen' => (int) Refund::whereIn('status', [Refund::PENDING, Refund::SUCCEEDED])->whereHas('order', fn ($q) => $q->where('buyer_id', $user->id))->sum('amount_sen'),
            ],
        ]);
    }
}

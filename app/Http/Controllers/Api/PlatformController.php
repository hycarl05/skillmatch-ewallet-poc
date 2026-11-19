<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformController extends Controller
{
    /** Trial balance: every account, and proof that total debits equal total credits. */
    public function ledger(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Platform, 403);

        $accounts = LedgerAccount::orderBy('id')->get()->map(fn ($a) => [
            'code' => $a->code,
            'name' => $a->name,
            'type' => $a->type,
            'debit_sen' => (int) $a->entries()->sum('debit_sen'),
            'credit_sen' => (int) $a->entries()->sum('credit_sen'),
            'balance_sen' => $a->balanceSen(),
        ]);

        return response()->json([
            'accounts' => $accounts,
            'total_debit_sen' => (int) LedgerEntry::sum('debit_sen'),
            'total_credit_sen' => (int) LedgerEntry::sum('credit_sen'),
            'webhooks' => WebhookEvent::latest('id')->limit(30)->get(['id', 'gateway', 'event_key', 'result', 'created_at']),
        ]);
    }
}

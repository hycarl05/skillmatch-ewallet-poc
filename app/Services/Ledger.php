<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Double-entry ledger. Every movement is recorded as a transaction whose
 * debits equal its credits, so the books always balance.
 *
 * SkillMatch never holds buyer money: "gateway_clearing" is money sitting at
 * the payment gateway, and the per-user accounts record who that money
 * belongs to while it is there.
 */
class Ledger
{
    public const GATEWAY_CLEARING = 'gateway_clearing';
    public const PLATFORM_BANK = 'platform_bank';
    public const PLATFORM_REVENUE = 'platform_revenue';
    public const SKILLPOINTS_DISCOUNT = 'skillpoints_discount';
    public const GATEWAY_FEES = 'gateway_fees';

    private const SYSTEM_ACCOUNTS = [
        self::GATEWAY_FEES => ['Payment gateway processing fees (paid by SkillMatch)', 'expense'],
        self::GATEWAY_CLEARING => ['Money at payment gateway', 'asset'],
        self::PLATFORM_BANK => ["SkillMatch's own bank (commission settled)", 'asset'],
        self::PLATFORM_REVENUE => ['SkillMatch commission', 'revenue'],
        self::SKILLPOINTS_DISCOUNT => ['SkillPoints discounts given', 'expense'],
    ];

    public function held(User $user): string
    {
        return $this->userAccount('held', $user, 'Held for %s until training is confirmed', 'liability');
    }

    public function payable(User $user): string
    {
        return $this->userAccount('payable', $user, 'Released to %s, awaiting payout', 'liability');
    }

    public function receivable(User $user): string
    {
        return $this->userAccount('receivable', $user, 'Owed back by %s after a refund', 'asset');
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int}>  $lines  [account code, debit sen, credit sen]
     */
    public function post(string $type, string $description, ?Order $order, array $lines): LedgerTransaction
    {
        $lines = array_values(array_filter($lines, fn ($l) => $l[1] > 0 || $l[2] > 0));
        $debits = array_sum(array_column($lines, 1));
        $credits = array_sum(array_column($lines, 2));

        if ($lines === [] || $debits !== $credits) {
            throw new InvalidArgumentException("Unbalanced ledger transaction '{$type}': debits {$debits} != credits {$credits}");
        }

        return DB::transaction(function () use ($type, $description, $order, $lines) {
            $transaction = LedgerTransaction::create([
                'order_id' => $order?->id,
                'type' => $type,
                'description' => $description,
            ]);

            foreach ($lines as [$code, $debit, $credit]) {
                $transaction->entries()->create([
                    'ledger_account_id' => $this->account($code)->id,
                    'debit_sen' => $debit,
                    'credit_sen' => $credit,
                ]);
            }

            return $transaction;
        });
    }

    public function balance(string $code): int
    {
        $account = LedgerAccount::where('code', $code)->first();

        return $account ? $account->balanceSen() : 0;
    }

    public function account(string $code): LedgerAccount
    {
        $existing = LedgerAccount::where('code', $code)->first();
        if ($existing) {
            return $existing;
        }

        if (! isset(self::SYSTEM_ACCOUNTS[$code])) {
            throw new InvalidArgumentException("Unknown ledger account '{$code}'");
        }

        [$name, $type] = self::SYSTEM_ACCOUNTS[$code];

        return LedgerAccount::create(['code' => $code, 'name' => $name, 'type' => $type]);
    }

    private function userAccount(string $prefix, User $user, string $name, string $type): string
    {
        $code = "{$prefix}:{$user->id}";

        LedgerAccount::firstOrCreate(
            ['code' => $code],
            ['name' => sprintf($name, $user->name), 'type' => $type, 'user_id' => $user->id],
        );

        return $code;
    }
}

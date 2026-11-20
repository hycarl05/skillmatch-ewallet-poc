<?php

namespace App\Http\Presenters;

use App\Enums\OrderStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Training;
use App\Models\User;

/** Turns models into the JSON the React app uses. */
class Present
{
    public static function user(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'organisation' => $user->organisation,
            'company_id' => $user->company_id,
        ];
    }

    public static function training(Training $training): array
    {
        return [
            'id' => $training->id,
            'title' => $training->title,
            'description' => $training->description,
            'price_sen' => $training->price_sen,
            'seller' => self::user($training->seller),
            'freelancer' => self::user($training->freelancer),
            'freelancer_share_sen' => $training->freelancer_share_sen,
        ];
    }

    public static function order(Order $order, User $viewer, bool $detail = false): array
    {
        $order->loadMissing('training.seller', 'training.freelancer', 'buyer', 'beneficiary', 'splits.recipient');

        $isTrainer = $order->isSeller($viewer) || $order->training->freelancer_id === $viewer->id;
        $isBuyer = $order->buyer_id === $viewer->id;
        $isPlatform = $viewer->role->value === 'platform';

        // Each person only gets the money figures that concern them:
        //   platform: everything (payment, every share, gateway fee, what SkillMatch keeps)
        //   buyer:    what they paid
        //   trainer:  what they receive
        //   attendee: (staff a company paid for) no money figures at all
        $viewAs = match (true) {
            $isPlatform => 'platform',
            $isBuyer => 'buyer',
            $isTrainer => 'trainer',
            default => 'attendee',
        };

        $split = fn ($s) => [
            'id' => $s->id,
            'role' => $s->role->value,
            'recipient' => self::user($s->recipient),
            'amount_sen' => $s->amount_sen,
            'status' => $s->status->value,
            'payout_reference' => $s->payout_reference,
        ];

        $data = [
            'id' => $order->id,
            'reference' => $order->reference,
            'view_as' => $viewAs,
            'status' => $order->status->value,
            'payout_mode' => $order->payout_mode->value,
            'gateway' => $order->gateway,
            'training' => ['id' => $order->training->id, 'title' => $order->training->title],
            'buyer' => self::user($order->buyer),
            'beneficiary' => self::user($order->beneficiary),
            'seller' => self::user($order->training->seller),
            'timestamps' => [
                'created_at' => $order->created_at,
                'paid_at' => $order->paid_at,
                'completed_at' => $order->completed_at,
                'confirmed_at' => $order->confirmed_at,
                'refunded_at' => $order->refunded_at,
            ],
            'can' => [
                'pay' => $isBuyer && $order->status === OrderStatus::PendingPayment,
                'complete' => $isTrainer && $order->status === OrderStatus::Paid,
                'confirm' => $isBuyer && $order->status === OrderStatus::Completed,
                'refund' => ($isBuyer && $order->status === OrderStatus::Paid)
                    || ($isPlatform && in_array($order->status, [OrderStatus::Paid, OrderStatus::Completed], true)),
            ],
            'checkout_url' => $isBuyer && $order->status === OrderStatus::PendingPayment ? $order->payments()->latest()->value('checkout_url') : null,
        ];

        if ($viewAs === 'platform' || $viewAs === 'buyer') {
            $data += [
                'gross_sen' => $order->gross_sen,
                'skillpoints_used' => $order->skillpoints_used,
                'discount_sen' => $order->discount_sen,
                'amount_sen' => $order->amount_sen,
            ];
        }

        if ($viewAs === 'platform') {
            $data += [
                'platform_fee_sen' => $order->platform_fee_sen,
                'platform_fee_percent' => $order->gross_sen > 0 ? round($order->platform_fee_sen * 100 / $order->gross_sen, 2) : 0,
                // Null until the gateway reports it (FakePay / Billplz / CHIP never do here).
                'processing_fee_sen' => $order->payments()->whereNotNull('gateway_fee_sen')->exists()
                    ? (int) $order->payments()->sum('gateway_fee_sen')
                    : null,
                'splits' => $order->splits->map($split)->values(),
            ];
        }

        if ($viewAs === 'trainer') {
            // Only their own share(s), never the buyer's payment or other people's cut.
            $data['my_splits'] = $order->splits->where('recipient_id', $viewer->id)->map($split)->values();
            $data['my_amount_sen'] = (int) $order->splits->where('recipient_id', $viewer->id)->sum('amount_sen');
        }

        if ($detail) {
            if ($viewAs === 'platform' || $viewAs === 'buyer') {
                $data['payments'] = $order->payments()->get(['id', 'gateway', 'gateway_reference', 'amount_sen', 'gateway_fee_sen', 'status', 'paid_at', 'created_at'])
                    ->each(fn ($p) => $viewAs === 'platform' ? null : $p->makeHidden('gateway_fee_sen'));
                $data['refunds'] = $order->refunds()->get(['id', 'amount_sen', 'status', 'gateway_reference', 'reason', 'created_at']);
            }

            // The ledger records every party's money, so only SkillMatch sees it.
            if ($viewAs === 'platform') {
                $data['ledger'] = $order->ledgerTransactions()->with('entries.account')->orderBy('id')->get()->map(fn ($t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'description' => $t->description,
                    'created_at' => $t->created_at,
                    'entries' => $t->entries->map(fn (LedgerEntry $e) => self::entry($e))->values(),
                ]);
            }
        }

        return $data;
    }

    public static function entry(LedgerEntry $entry): array
    {
        return [
            'account' => $entry->account->code,
            'account_name' => $entry->account->name,
            'debit_sen' => $entry->debit_sen,
            'credit_sen' => $entry->credit_sen,
        ];
    }
}

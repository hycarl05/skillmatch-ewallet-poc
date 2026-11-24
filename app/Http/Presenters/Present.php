<?php

namespace App\Http\Presenters;

use App\Enums\OrderStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Training;
use App\Models\User;
use App\Services\RefundService;

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

        $requestBlocker = $isBuyer ? app(RefundService::class)->requestBlocker($order) : null;

        $split = fn ($s) => [
            'id' => $s->id,
            'role' => $s->role->value,
            'recipient' => self::user($s->recipient),
            'amount_sen' => $s->amount_sen,
            'refunded_sen' => $s->refunded_sen,
            'status' => $s->status->value,
            'payout_reference' => $s->payout_reference,
            'payout_offset_sen' => $s->payout_offset_sen,
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
                'request_refund' => $isBuyer && $requestBlocker === null,
                'refund' => $isPlatform && app(RefundService::class)->refundBlocker($order) === null,
            ],
            // Shown instead of the refund button, so the buyer knows why there isn't one.
            'refund_blocker' => $requestBlocker,
            'checkout_url' => $isBuyer && $order->status === OrderStatus::PendingPayment ? $order->payments()->latest()->value('checkout_url') : null,
        ];

        if ($viewAs === 'platform' || $viewAs === 'buyer') {
            $data += [
                'gross_sen' => $order->gross_sen,
                'skillpoints_used' => $order->skillpoints_used,
                'discount_sen' => $order->discount_sen,
                'amount_sen' => $order->amount_sen,
                'refunded_sen' => $order->refundedSen(),
                'refundable_sen' => $order->refundableSen(),
                // For the refund form: the most the buyer can ask for now, and the
                // processing fee a buyer-paid refund shares in (null = not known).
                'refund_policy' => [
                    'buyer_max_sen' => app(RefundService::class)->buyerMaxSen($order),
                    'buyer_max_percent' => config('skillmatch.refunds.buyer_max_percent')[$order->status->value] ?? null,
                    'payment_fee_sen' => $order->payments()->whereIn('status', ['paid', 'refunded'])->latest('id')->value('gateway_fee_sen'),
                ],
                'open_refund_request' => $order->refunds()->where('status', Refund::REQUESTED)->exists(),
                'disputed' => $order->hasOpenDispute(),
            ];
        }

        if ($viewAs === 'platform') {
            $data += [
                'platform_fee_sen' => $order->platform_fee_sen,
                'platform_fee_percent' => $order->gross_sen > 0 ? round($order->platform_fee_sen * 100 / $order->gross_sen, 2) : 0,
                // Null until the gateway reports it (FakePay / Billplz / CHIP never do here).
                'refund_fees_recovered_sen' => $order->refundFeesRecoveredSen(),
                'processing_fee_sen' => $order->payments()->whereNotNull('gateway_fee_sen')->exists()
                    ? (int) $order->payments()->sum('gateway_fee_sen')
                    : null,
                'splits' => $order->splits->map($split)->values(),
            ];
        }

        if ($viewAs === 'trainer') {
            // Only their own share(s), never the buyer's payment or other people's cut.
            $data['my_splits'] = $order->splits->where('recipient_id', $viewer->id)->map($split)->values();
            $mine = $order->splits->where('recipient_id', $viewer->id);
            $data['my_amount_sen'] = (int) $mine->sum(fn ($s) => $s->remainingSen()); // after refunds
            $data['my_refunded_sen'] = (int) $mine->sum('refunded_sen');
        }

        if ($detail) {
            if ($viewAs === 'platform' || $viewAs === 'buyer') {
                $data['payments'] = $order->payments()->get(['id', 'gateway', 'gateway_reference', 'amount_sen', 'gateway_fee_sen', 'status', 'paid_at', 'created_at', 'dispute_reference', 'dispute_status', 'dispute_reason', 'dispute_amount_sen'])
                    ->each(fn ($p) => $viewAs === 'platform' ? null : $p->makeHidden(['gateway_fee_sen', 'dispute_reference', 'dispute_reason']));
                $data['refunds'] = $order->refunds()->with('requester', 'reviewer')->latest('id')->get()->map(fn (Refund $r) => self::refund($r, $viewAs))->values();
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

    /** The buyer sees their refunds; only SkillMatch sees how each was split and the clawbacks. */
    public static function refund(Refund $refund, string $viewAs): array
    {
        $data = [
            'id' => $refund->id,
            'source' => $refund->source,
            'amount_sen' => $refund->amount_sen, // what the buyer gets
            'gross_sen' => $refund->gross_sen ?? $refund->amount_sen,
            'fee_paid_by' => $refund->fee_paid_by,
            // The buyer only sees the fee when it came out of their refund.
            'fee_sen' => $viewAs === 'platform' || $refund->fee_paid_by === Refund::FEE_BUYER ? $refund->fee_sen : null,
            'status' => $refund->status,
            'reason' => $refund->reason,
            'review_note' => $refund->review_note,
            'created_at' => $refund->created_at,
            'reviewed_at' => $refund->reviewed_at,
            'completed_at' => $refund->completed_at,
        ];

        if ($viewAs !== 'platform') {
            return $data;
        }

        $outstanding = $refund->outstandingClawbacks();

        return $data + [
            'gateway_reference' => $refund->gateway_reference,
            'requested_by' => $refund->requester?->name,
            'reviewed_by' => $refund->reviewer?->name,
            'attempts' => $refund->attempts,
            'failure_reason' => $refund->failure_reason,
            'allocation' => $refund->allocation ?? [],
            'actions' => [
                'approve' => $refund->status === Refund::REQUESTED,
                'reject' => $refund->status === Refund::REQUESTED,
                'retry' => $refund->status === Refund::FAILED
                    || (in_array($refund->status, [Refund::PENDING, Refund::SUCCEEDED], true) && $outstanding !== [] && $refund->order->payout_mode->value === 'deferred'),
                'mark_sent' => in_array($refund->status, Refund::OWED, true),
            ],
        ];
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

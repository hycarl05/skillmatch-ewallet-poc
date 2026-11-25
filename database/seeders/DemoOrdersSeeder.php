<?php

namespace Database\Seeders;

use App\Enums\PayoutMode;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Training;
use App\Models\User;
use App\Services\OrderService;
use App\Services\RefundService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * About 60 days of demo orders so the admin dashboard has something to show.
 *
 *   php artisan db:seed --class=DemoOrdersSeeder
 *
 * Every order goes through OrderService / RefundService with the clock set
 * back, exactly as if it had happened on that day: splits, ledger, SkillPoints
 * and payouts all stay correct and the books balance. FakePay only, so no
 * gateway account is needed. Same result every run (fixed random seed); it
 * refuses to run twice.
 */
class DemoOrdersSeeder extends Seeder
{
    private const DAYS = 60;

    private const EXTRA_BUYERS = ['Ravi (individual)', 'Siti (individual)', 'Daniel (individual)'];

    public function run(OrderService $orders, RefundService $refunds): void
    {
        if (User::where('name', self::EXTRA_BUYERS[0])->exists()) {
            $this->command?->warn('Demo orders are already seeded. Start again with: php artisan migrate:fresh --seed && php artisan db:seed --class=DemoOrdersSeeder');

            return;
        }

        mt_srand(20261125);
        $realNow = Carbon::now();
        $admin = User::where('role', UserRole::Platform)->firstOrFail();

        foreach (self::EXTRA_BUYERS as $name) {
            $buyer = User::factory()->create([
                'name' => $name,
                'email' => trim(strtolower(preg_replace('/[^a-z]+/i', '.', $name)), '.').'@demo.skillmatch.test',
                'role' => UserRole::Individual,
            ]);
            $buyer->skillpointTransactions()->create(['points' => 2000, 'reason' => 'Welcome bonus']);
        }

        $purchases = $this->purchaseOptions();
        $created = 0;

        try {
            for ($daysAgo = self::DAYS - 1; $daysAgo >= 0; $daysAgo--) {
                $day = $realNow->copy()->subDays($daysAgo);
                foreach (range(1, $this->ordersOn($day, $daysAgo)) as $_) {
                    $at = $day->copy()->setTime(mt_rand(8, 22), mt_rand(0, 59), mt_rand(0, 59));
                    if ($at->greaterThan($realNow)) {
                        $at = $realNow->copy()->subMinutes(mt_rand(5, 120));
                    }
                    $this->placeOrder($orders, $refunds, $admin, $purchases[array_rand($purchases)], $at, $realNow);
                    $created++;
                }
            }
            // Always leave a couple of fresh requests so "Refund requests to decide" has work in it.
            $recent = Order::where('status', 'paid')->whereDoesntHave('refunds')->latest('paid_at')->limit(2)->get();
            foreach ($recent as $i => $order) {
                Carbon::setTestNow($realNow->copy()->subMinutes(30 + $i * 90));
                $refunds->request($order, $order->buyer, $i ? intdiv($order->amount_sen, 2) : null, $i ? 'Can only attend half of it' : 'Trainer rescheduled to a date I can\'t make');
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info("Created {$created} demo orders over the last ".self::DAYS.' days.');
    }

    /** Fewer orders at weekends and further back, so the chart has a shape. */
    private function ordersOn(Carbon $day, int $daysAgo): int
    {
        $base = $day->isWeekend() ? mt_rand(0, 1) : mt_rand(1, 3);
        $growth = $daysAgo < 20 ? mt_rand(0, 2) : 0;

        return max(1, $base + $growth);
    }

    /** @return list<array{buyer: User, training: Training, beneficiary: ?User}> Who buys what, following the README's flows. */
    private function purchaseOptions(): array
    {
        $user = fn (string $prefix) => User::where('name', 'like', "{$prefix}%")->firstOrFail();
        $training = fn (string $prefix) => Training::where('title', 'like', "{$prefix}%")->firstOrFail();

        $individuals = User::where('role', UserRole::Individual)->get();
        $hr = $user('Nadia');
        $staff = $hr->staff;
        $provider = $user('Akademi Digital');

        $options = [];
        foreach ($individuals as $buyer) {
            foreach (['Laravel', 'Leadership', 'Excel', 'Public Speaking'] as $t) {
                $options[] = ['buyer' => $buyer, 'training' => $training($t), 'beneficiary' => null];
            }
        }
        foreach ($staff as $member) {
            foreach (['Excel', 'Excel', 'Leadership', 'Laravel'] as $t) {
                $options[] = ['buyer' => $hr, 'training' => $training($t), 'beneficiary' => $member];
            }
        }
        foreach (['Public Speaking', 'Public Speaking', 'Laravel'] as $t) {
            $options[] = ['buyer' => $provider, 'training' => $training($t), 'beneficiary' => null];
        }

        return $options;
    }

    private function placeOrder(OrderService $orders, RefundService $refunds, User $admin, array $p, Carbon $at, Carbon $realNow): void
    {
        Carbon::setTestNow($at);
        $daysAgo = $at->diffInDays($realNow);

        $quote = $orders->quote($p['training'], $p['buyer']);
        $points = mt_rand(1, 100) <= 20 ? min(mt_rand(3, 15) * 100, $quote['max_points']) : 0;
        $mode = mt_rand(1, 100) <= 25 ? PayoutMode::Instant : PayoutMode::Deferred;

        $order = $orders->create($p['buyer'], $p['training'], $p['beneficiary'], $points, 'fake', $mode)['order'];
        $payment = $order->payments()->first();

        // Fresh orders may still be waiting for the buyer to pay; a few payments fail.
        $roll = mt_rand(1, 100);
        if ($daysAgo < 2 && $roll <= 25) {
            return;
        }
        if ($roll > 94) {
            Carbon::setTestNow($at->copy()->addMinutes(mt_rand(2, 20)));
            $orders->markPaymentFailed($payment);

            return;
        }

        Carbon::setTestNow($at->copy()->addMinutes(mt_rand(1, 10)));
        $orders->markPaid($payment, $payment->amount_sen);
        $orders->recordProcessingFee($payment->fresh());
        $order->refresh();

        // Some buyers change their mind before the training happens.
        $refundRoll = mt_rand(1, 100);
        if ($refundRoll <= 8) {
            $this->buyerRefund($refunds, $admin, $order, $at, $realNow, partial: mt_rand(0, 1) === 1);

            return;
        }

        // The trainer delivers 1–4 days later, the buyer confirms 1–3 days after that.
        $completedAt = $at->copy()->addDays(mt_rand(1, 4))->setTime(mt_rand(9, 18), mt_rand(0, 59));
        if ($completedAt->greaterThan($realNow)) {
            return;
        }
        Carbon::setTestNow($completedAt);
        $orders->complete($order, $order->training->freelancer ?? $order->training->seller);

        $confirmedAt = $completedAt->copy()->addDays(mt_rand(1, 3))->setTime(mt_rand(9, 22), mt_rand(0, 59));
        if ($confirmedAt->greaterThan($realNow)) {
            return;
        }
        Carbon::setTestNow($confirmedAt);
        $order = $orders->confirm($order->fresh(), $order->buyer);

        // Now and then SkillMatch refunds part of a delivered training as goodwill.
        if ($refundRoll >= 96) {
            $refundAt = $confirmedAt->copy()->addDays(mt_rand(1, 5));
            if ($refundAt->lessThan($realNow)) {
                Carbon::setTestNow($refundAt);
                $refunds->refundNow($order, $admin, intdiv($order->amount_sen, 4), 'Goodwill: session started late', Refund::FEE_SKILLMATCH);
            }
        }
    }

    /** The buyer asks; SkillMatch approves within a day or so. Recent ones are left for the admin to decide. */
    private function buyerRefund(RefundService $refunds, User $admin, Order $order, Carbon $at, Carbon $realNow, bool $partial): void
    {
        $askedAt = $at->copy()->addHours(mt_rand(2, 36));
        if ($askedAt->greaterThan($realNow)) {
            return;
        }
        Carbon::setTestNow($askedAt);
        $amount = $partial ? intdiv($order->amount_sen, 2) : null;
        $refund = $refunds->request($order, $order->buyer, $amount, $partial ? 'Can only attend the first day' : 'Schedule clash, changed my mind');

        $decidedAt = $askedAt->copy()->addHours(mt_rand(4, 30));
        if ($decidedAt->greaterThan($realNow) || $at->diffInDays($realNow) < 4) {
            return; // still waiting: shows up under "Refund requests to decide"
        }
        Carbon::setTestNow($decidedAt);
        $refunds->approve($refund, $admin, null, 'Approved', Refund::FEE_BUYER);
    }
}

<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\SubscriptionGateway;
use App\Payments\WebhookResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Subscription plans (Tier Plan sheet): subscribe, renew, grace, expire, cancel.
 *
 *   pending -> active -> (renewal unpaid) past_due -> (grace over) expired
 *                    \-> (cancelled) active until period end -> cancelled
 *
 * Collection follows the gateways' rules:
 *  - card on Stripe: Stripe Billing renews the card every month. FPX can't be
 *    used here: Stripe only supports it for one-off payments.
 *  - invoice: SkillMatch issues an invoice before each period ends and the
 *    subscriber pays it at a one-off checkout (FPX or card). Unpaid by the
 *    period end: past due, features stay on for the grace period, then off.
 *
 * Subscription fees are SkillMatch's own revenue (nothing is owed to a
 * trainer), so the ledger books them straight to SkillMatch's bank.
 */
class SubscriptionService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly Ledger $ledger,
        private readonly Entitlements $entitlements,
    ) {}

    /** @return \Illuminate\Support\Collection<int, Plan> */
    public function plansFor(User $user)
    {
        return Plan::where('active', true)->where('audience', $user->role->value)->orderBy('tier')->get();
    }

    /** @return array{subscription: Subscription, checkout_url: string} */
    public function subscribe(User $user, Plan $plan, string $gatewayName, string $collection): array
    {
        $this->validateSubscribe($user, $plan, $gatewayName, $collection);

        $subscription = DB::transaction(function () use ($user, $plan, $gatewayName, $collection) {
            // An unfinished checkout for another plan is abandoned.
            foreach ($user->subscriptions()->where('status', Subscription::PENDING)->get() as $old) {
                $this->end($old, Subscription::EXPIRED);
            }

            return $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => Subscription::PENDING,
                'gateway' => $gatewayName,
                'collection' => $collection,
                'price_sen' => $plan->price_sen,
            ]);
        });

        $gateway = $this->gateway($gatewayName);
        $returnUrl = url('/plans?returned=1');

        try {
            if ($subscription->renewsAtGateway()) {
                $session = $gateway->startCardSubscription($subscription, $returnUrl);
            } else {
                $invoice = $this->openInvoice($subscription, now(), $this->periodEnd($subscription, now()));
                $session = $gateway->checkoutInvoice($invoice, $returnUrl);
                $invoice->update(['gateway_reference' => $session->reference, 'checkout_url' => $session->url]);
            }
        } catch (Throwable $e) {
            Log::error('Subscription checkout failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
            DB::transaction(fn () => $this->end($subscription, Subscription::EXPIRED));

            throw ValidationException::withMessages(['gateway' => "Could not start payment with {$gatewayName}: {$e->getMessage()}"]);
        }

        return ['subscription' => $subscription->fresh('plan'), 'checkout_url' => $session->url];
    }

    /** A verified subscription webhook. Safe to apply more than once. */
    public function applyWebhook(string $gatewayName, WebhookResult $result): string
    {
        return match ($result->subject) {
            'invoice' => $this->applyInvoiceWebhook($gatewayName, $result),
            'checkout' => $this->applyCardCheckout($result),
            'renewal' => $this->applyGatewayRenewal($gatewayName, $result),
            'subscription' => $this->applyGatewaySubscriptionEnded($result),
            default => 'ignored',
        };
    }

    public function markInvoicePaid(SubscriptionInvoice $invoice, ?int $paidAmountSen): string
    {
        return DB::transaction(function () use ($invoice, $paidAmountSen) {
            $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $subscription = Subscription::whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === SubscriptionInvoice::PAID) {
                return 'already_paid';
            }
            if ($paidAmountSen !== null && $paidAmountSen !== $invoice->amount_sen) {
                Log::warning('Subscription invoice paid amount does not match', ['invoice' => $invoice->id, 'expected' => $invoice->amount_sen, 'got' => $paidAmountSen]);

                return 'amount_mismatch';
            }

            $invoice->update([
                'status' => SubscriptionInvoice::PAID,
                'paid_at' => now(),
                'number' => $this->invoiceNumber($invoice),
            ]);

            $this->bookRevenue($invoice, $subscription);
            $this->extendTo($subscription, $invoice->period_start, $invoice->period_end);

            return 'subscription_paid';
        });
    }

    /** The subscriber stops their plan. It stays on until the paid period ends. */
    public function cancel(Subscription $subscription, User $by): Subscription
    {
        abort_unless($subscription->user_id === $by->id || $by->role === UserRole::Platform, 403);

        if ($subscription->status === Subscription::PENDING) {
            DB::transaction(fn () => $this->end($subscription, Subscription::EXPIRED));

            return $subscription->fresh();
        }

        if (! in_array($subscription->status, [Subscription::ACTIVE, Subscription::PAST_DUE], true)) {
            throw ValidationException::withMessages(['subscription' => 'This subscription has already ended.']);
        }

        if ($subscription->renewsAtGateway()) {
            $this->gateway($subscription->gateway)->cancelAtPeriodEnd($subscription);
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update(['cancel_at_period_end' => true]);
            $subscription->invoices()->where('status', SubscriptionInvoice::OPEN)->update(['status' => SubscriptionInvoice::VOID]);

            // Past due and now cancelled: nothing more is coming, end it.
            if ($subscription->status === Subscription::PAST_DUE) {
                $this->end($subscription, Subscription::CANCELLED);
            }
        });

        return $subscription->fresh();
    }

    /**
     * Daily job. Issues renewal invoices ahead of the period end, marks unpaid
     * subscriptions past due, and ends them after the grace period.
     *
     * @return array<string, int>
     */
    public function runRenewals(): array
    {
        $counts = ['invoiced' => 0, 'auto_charged' => 0, 'past_due' => 0, 'expired' => 0, 'cancelled' => 0, 'abandoned' => 0];
        $noticeDays = (int) config('skillmatch.subscriptions.renew_notice_days');
        $graceDays = (int) config('skillmatch.subscriptions.grace_days');

        // 1. Renewal invoices for subscriptions SkillMatch renews (not Stripe card ones).
        $due = Subscription::with('plan')
            ->where('status', Subscription::ACTIVE)
            ->where('cancel_at_period_end', false)
            ->where('current_period_end', '<=', now()->addDays($noticeDays))
            ->get()
            ->reject(fn (Subscription $s) => $s->renewsAtGateway())
            ->reject(fn (Subscription $s) => $s->invoices()->whereIn('status', [SubscriptionInvoice::OPEN, SubscriptionInvoice::PAID])->where('period_start', '>=', $s->current_period_end)->exists());

        foreach ($due as $subscription) {
            $invoice = DB::transaction(fn () => $this->openInvoice($subscription, $subscription->current_period_end, $this->periodEnd($subscription, $subscription->current_period_end)));

            // The simulator has no stored cards, so a "card" subscriber is
            // charged here, as Stripe Billing would do for a real card.
            if ($subscription->gateway === 'fake' && $subscription->collection === Subscription::COLLECTION_CARD) {
                $invoice->update(['gateway_reference' => 'fakesub_auto_'.Str::lower(Str::random(10))]);
                $this->markInvoicePaid($invoice, $invoice->amount_sen);
                $counts['auto_charged']++;

                continue;
            }

            try {
                $session = $this->gateway($subscription->gateway)->checkoutInvoice($invoice, url('/plans?returned=1'));
                $invoice->update(['gateway_reference' => $session->reference, 'checkout_url' => $session->url]);
                $counts['invoiced']++;
            } catch (Throwable $e) {
                // The invoice stays open without a link; the next run tries again.
                Log::warning('Could not create renewal checkout', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
                $invoice->delete();
            }
        }

        DB::transaction(function () use (&$counts, $graceDays) {
            // 2. Period over: cancelled ones end; unpaid ones go past due.
            foreach (Subscription::where('status', Subscription::ACTIVE)->where('current_period_end', '<', now())->get() as $subscription) {
                if ($subscription->cancel_at_period_end) {
                    $this->end($subscription, Subscription::CANCELLED);
                    $counts['cancelled']++;
                } else {
                    $subscription->update([
                        'status' => Subscription::PAST_DUE,
                        'grace_ends_at' => $subscription->current_period_end->copy()->addDays($graceDays),
                    ]);
                    $counts['past_due']++;
                }
            }

            // 3. Grace over: the plan ends and the user is back on the free level.
            foreach (Subscription::where('status', Subscription::PAST_DUE)->where('grace_ends_at', '<=', now())->get() as $subscription) {
                $this->end($subscription, Subscription::EXPIRED);
                $counts['expired']++;
            }

            // 4. Checkouts never finished.
            foreach (Subscription::where('status', Subscription::PENDING)->where('created_at', '<=', now()->subDay())->get() as $subscription) {
                $this->end($subscription, Subscription::EXPIRED);
                $counts['abandoned']++;
            }
        });

        return $counts;
    }

    // ------------------------------------------------------------- webhooks

    private function applyInvoiceWebhook(string $gatewayName, WebhookResult $result): string
    {
        $invoice = SubscriptionInvoice::where('gateway', $gatewayName)->where('gateway_reference', $result->reference)->first()
            ?? ($result->ownReference ? SubscriptionInvoice::where('gateway', $gatewayName)->find($result->ownReference) : null);

        if (! $invoice) {
            return 'unknown_subscription_invoice';
        }

        return match ($result->status) {
            WebhookResult::PAID => $this->markInvoicePaid($invoice, $result->amountSen),
            WebhookResult::FAILED => $this->markInvoiceFailed($invoice),
            default => 'pending',
        };
    }

    private function markInvoiceFailed(SubscriptionInvoice $invoice): string
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== SubscriptionInvoice::OPEN) {
                return "ignored_{$invoice->status}";
            }

            // A failed FPX attempt doesn't end anything: the invoice stays
            // payable from the Plans page until the period and grace run out.
            $subscription = $invoice->subscription;
            if ($subscription->status === Subscription::PENDING) {
                $invoice->update(['status' => SubscriptionInvoice::FAILED]);
                $this->end($subscription, Subscription::EXPIRED);

                return 'subscription_failed';
            }

            return 'payment_failed';
        });
    }

    /** Stripe card subscription: checkout finished. Access starts now; invoice.paid books the money. */
    private function applyCardCheckout(WebhookResult $result): string
    {
        $subscription = $result->ownReference ? Subscription::find($result->ownReference) : null;
        if (! $subscription) {
            return 'unknown_subscription';
        }
        if ($result->status === WebhookResult::FAILED) {
            if ($subscription->status === Subscription::PENDING) {
                $this->end($subscription, Subscription::EXPIRED);
            }

            return 'subscription_failed';
        }
        if ($result->status !== WebhookResult::PAID) {
            return 'pending';
        }

        $subscription->update(['gateway_subscription_id' => $result->gatewayPaymentId ?? $subscription->gateway_subscription_id]);

        if ($subscription->status === Subscription::PENDING) {
            // Provisional period until invoice.paid gives Stripe's exact dates.
            $this->extendTo($subscription, now(), $this->periodEnd($subscription, now()));
        }

        return 'subscription_started';
    }

    /** Stripe charged a card subscription for a period (or failed to). */
    private function applyGatewayRenewal(string $gatewayName, WebhookResult $result): string
    {
        $subscription = ($result->ownReference ? Subscription::find($result->ownReference) : null)
            ?? ($result->gatewayPaymentId ? Subscription::where('gateway_subscription_id', $result->gatewayPaymentId)->first() : null);

        if (! $subscription) {
            return 'unknown_subscription';
        }

        if ($result->status === WebhookResult::FAILED) {
            // Stripe retries the card on its own schedule; features stay on for the grace period.
            if ($subscription->status === Subscription::ACTIVE) {
                $subscription->update([
                    'status' => Subscription::PAST_DUE,
                    'grace_ends_at' => now()->addDays((int) config('skillmatch.subscriptions.grace_days')),
                ]);
            }

            return 'renewal_failed';
        }

        $start = $result->periodStart ? Carbon::createFromTimestamp($result->periodStart) : now();
        $end = $result->periodEnd ? Carbon::createFromTimestamp($result->periodEnd) : $this->periodEnd($subscription, $start);

        $invoice = SubscriptionInvoice::firstOrCreate(
            ['gateway' => $gatewayName, 'gateway_reference' => $result->reference],
            [
                'subscription_id' => $subscription->id,
                'amount_sen' => (int) $result->amountSen,
                'period_start' => $start,
                'period_end' => $end,
                'status' => SubscriptionInvoice::OPEN,
            ],
        );

        $subscription->update(['gateway_subscription_id' => $subscription->gateway_subscription_id ?? $result->gatewayPaymentId]);

        return $this->markInvoicePaid($invoice, null);
    }

    private function applyGatewaySubscriptionEnded(WebhookResult $result): string
    {
        $subscription = ($result->ownReference ? Subscription::find($result->ownReference) : null)
            ?? Subscription::where('gateway_subscription_id', $result->reference)->first();

        if (! $subscription || in_array($subscription->status, [Subscription::CANCELLED, Subscription::EXPIRED], true)) {
            return 'ignored';
        }

        $this->end($subscription, $subscription->cancel_at_period_end ? Subscription::CANCELLED : Subscription::EXPIRED);

        return 'subscription_ended';
    }

    // ------------------------------------------------------------- helpers

    private function validateSubscribe(User $user, Plan $plan, string $gatewayName, string $collection): void
    {
        if (! $plan->active || $plan->audience !== $user->role->value) {
            throw ValidationException::withMessages(['plan_id' => 'This plan is not available for your account type.']);
        }
        if (! in_array($gatewayName, config('skillmatch.subscriptions.gateways'), true)) {
            throw ValidationException::withMessages(['gateway' => "{$gatewayName} can't collect subscription fees."]);
        }
        if (! in_array($collection, [Subscription::COLLECTION_CARD, Subscription::COLLECTION_INVOICE], true)) {
            throw ValidationException::withMessages(['collection' => 'Choose card or invoice.']);
        }

        if ($plan->max_staff !== null && ($staff = $user->staff()->count()) > $plan->max_staff) {
            throw ValidationException::withMessages(['plan_id' => "Your company has {$staff} staff; this plan covers up to {$plan->max_staff}."]);
        }

        if ($current = $this->entitlements->activeSubscription($user)) {
            throw ValidationException::withMessages([
                'plan_id' => "You're on the {$current->plan->name} plan. Cancel it first; you can subscribe again when it ends.",
            ]);
        }
    }

    private function gateway(string $name): SubscriptionGateway
    {
        $gateway = $this->gateways->gateway($name);

        if (! $gateway instanceof SubscriptionGateway) {
            throw ValidationException::withMessages(['gateway' => "{$name} can't collect subscription fees."]);
        }

        return $gateway;
    }

    private function openInvoice(Subscription $subscription, Carbon $start, Carbon $end): SubscriptionInvoice
    {
        return $subscription->invoices()->create([
            'amount_sen' => $subscription->price_sen,
            'period_start' => $start,
            'period_end' => $end,
            'status' => SubscriptionInvoice::OPEN,
            'gateway' => $subscription->gateway,
        ]);
    }

    private function periodEnd(Subscription $subscription, Carbon $start): Carbon
    {
        $subscription->loadMissing('plan');

        return $subscription->plan->interval === 'year' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();
    }

    /** Paid for this period: active, and the period moves forward (never back). */
    private function extendTo(Subscription $subscription, Carbon $start, Carbon $end): void
    {
        $isNew = $subscription->status === Subscription::PENDING || $subscription->current_period_end === null;

        $subscription->update([
            'status' => Subscription::ACTIVE,
            'current_period_start' => $isNew || $end->gt($subscription->current_period_end) ? $start : $subscription->current_period_start,
            'current_period_end' => $isNew || $end->gt($subscription->current_period_end) ? $end : $subscription->current_period_end,
            'grace_ends_at' => null,
        ]);
    }

    private function bookRevenue(SubscriptionInvoice $invoice, Subscription $subscription): void
    {
        $subscription->loadMissing('plan', 'user');
        $label = "{$subscription->plan->name} subscription for {$subscription->user->name}, invoice {$invoice->number}";

        $this->ledger->post('subscription_payment', "Payment received: {$label}", null, [
            [Ledger::GATEWAY_CLEARING, $invoice->amount_sen, 0],
            [Ledger::SUBSCRIPTION_REVENUE, 0, $invoice->amount_sen],
        ]);
        // Nothing in it belongs to anyone else: settled to SkillMatch's bank.
        $this->ledger->post('platform_settlement', "Settled: {$label}", null, [
            [Ledger::PLATFORM_BANK, $invoice->amount_sen, 0],
            [Ledger::GATEWAY_CLEARING, 0, $invoice->amount_sen],
        ]);
    }

    private function invoiceNumber(SubscriptionInvoice $invoice): string
    {
        return sprintf('SUB-%s-%06d', now()->format('Y'), $invoice->id);
    }

    private function end(Subscription $subscription, string $status): void
    {
        $subscription->update(['status' => $status, 'ended_at' => now(), 'grace_ends_at' => null]);
        $subscription->invoices()->where('status', SubscriptionInvoice::OPEN)->update(['status' => SubscriptionInvoice::VOID]);
    }
}

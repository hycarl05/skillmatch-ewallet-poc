<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Payments\CheckoutSession;
use App\Payments\InvalidWebhookSignature;
use App\Payments\PaymentGateway;
use App\Payments\RefundResult;
use App\Payments\ReportsProcessingFee;
use App\Payments\SubscriptionGateway;
use App\Payments\WebhookResult;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe Checkout, with Stripe Connect "separate charges and transfers".
 *
 * The buyer pays SkillMatch's Stripe account. Each trainer's share is moved
 * to their Connect (Express) account by StripeConnectPayoutProvider when the
 * training is confirmed (deferred mode). Until then the money sits in
 * SkillMatch's Stripe balance, the same trade-off as CHIP Collect + Send.
 *
 * Stripe's session id is the payment's gateway_reference.
 */
class StripeGateway implements PaymentGateway, ReportsProcessingFee, SubscriptionGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckout(Order $order, Payment $payment, string $callbackUrl, string $returnUrl): CheckoutSession
    {
        $order->loadMissing('buyer', 'training');

        // Stripe sends webhooks to the endpoint set up in the dashboard (or
        // `stripe listen`), so $callbackUrl isn't passed per session.
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'myr',
                    'unit_amount' => $payment->amount_sen,
                    'product_data' => ['name' => mb_substr($order->training->title, 0, 250)],
                ],
            ]],
            'customer_email' => $order->buyer->email,
            'client_reference_id' => $order->reference,
            'metadata' => ['order_reference' => $order->reference, 'payment_id' => (string) $payment->id],
            // Links the charge to the transfers made at payout time.
            'payment_intent_data' => [
                'transfer_group' => $order->reference,
                'metadata' => ['order_reference' => $order->reference],
            ],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        // The order reference is random, so the key stays unique even when
        // database ids are reused (rolled-back transactions, migrate:fresh).
        ], ['idempotency_key' => "checkout-{$order->reference}-{$payment->id}"]);

        return new CheckoutSession($session->id, $session->url);
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        $secret = (string) config('skillmatch.gateways.stripe.webhook_secret');
        if ($secret === '') {
            throw new InvalidWebhookSignature('STRIPE_WEBHOOK_SECRET is not set');
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new InvalidWebhookSignature('Stripe signature mismatch: '.$e->getMessage());
        }

        $object = $event->data->object;

        if ($subscriptionResult = $this->subscriptionWebhook($event->type, $event->id, $object->toArray(), $event->toArray())) {
            return $subscriptionResult;
        }

        // refund.created / refund.updated / refund.failed / charge.refund.updated
        if (($object->object ?? null) === 'refund') {
            $metadata = $object->metadata ?? null;

            return new WebhookResult(
                reference: (string) $object->id,
                status: match ($object->status) {
                    'succeeded' => WebhookResult::SUCCEEDED,
                    'failed', 'canceled' => WebhookResult::FAILED,
                    default => WebhookResult::PENDING,
                },
                amountSen: (int) $object->amount,
                eventKey: $event->id,
                payload: $event->toArray(),
                kind: WebhookResult::KIND_REFUND,
                // Matches a refund made in the Stripe dashboard to its payment.
                gatewayPaymentId: is_string($object->payment_intent ?? null) ? $object->payment_intent : null,
                message: $object->failure_reason ?? $object->reason ?? null,
                ownReference: isset($metadata['skillmatch_refund_id']) ? (string) $metadata['skillmatch_refund_id'] : null,
            );
        }

        // charge.dispute.created / updated / closed
        if (($object->object ?? null) === 'dispute') {
            return new WebhookResult(
                reference: (string) $object->id,
                status: match ($object->status) {
                    'won', 'warning_closed', 'prevented' => WebhookResult::DISPUTE_WON,
                    'lost' => WebhookResult::DISPUTE_LOST,
                    default => WebhookResult::DISPUTE_OPEN,
                },
                amountSen: (int) $object->amount,
                eventKey: $event->id,
                payload: $event->toArray(),
                kind: WebhookResult::KIND_DISPUTE,
                gatewayPaymentId: is_string($object->payment_intent ?? null) ? $object->payment_intent : null,
                message: $object->reason ?? null,
            );
        }

        $isSession = ($object->object ?? null) === 'checkout.session';

        // FPX and other bank redirects can complete asynchronously, so a
        // completed session isn't necessarily paid yet.
        $status = match ($event->type) {
            'checkout.session.completed' => $object->payment_status === 'paid' ? WebhookResult::PAID : WebhookResult::PENDING,
            'checkout.session.async_payment_succeeded' => WebhookResult::PAID,
            'checkout.session.async_payment_failed', 'checkout.session.expired' => WebhookResult::FAILED,
            default => WebhookResult::PENDING,
        };

        return new WebhookResult(
            reference: $isSession ? (string) $object->id : '',
            status: $status,
            amountSen: $isSession && $object->amount_total !== null ? (int) $object->amount_total : null,
            eventKey: $event->id,
            payload: $event->toArray(),
            gatewayPaymentId: $isSession && is_string($object->payment_intent ?? null) ? $object->payment_intent : null,
        );
    }

    public function refund(Payment $payment, int $amountSen, string $idempotencyKey, array $metadata = []): RefundResult
    {
        try {
            $paymentIntent = $payment->gateway_payment_id
                ?? $this->stripe->checkout->sessions->retrieve($payment->gateway_reference)->payment_intent;

            $refund = $this->stripe->refunds->create([
                'payment_intent' => $paymentIntent,
                'amount' => $amountSen,
                'metadata' => ['payment_id' => (string) $payment->id] + $metadata,
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $e) {
            return new RefundResult(RefundResult::FAILED, message: 'Stripe refund failed: '.$e->getMessage());
        }

        return match ($refund->status) {
            'succeeded' => new RefundResult(RefundResult::SUCCEEDED, $refund->id),
            'failed', 'canceled' => new RefundResult(RefundResult::FAILED, $refund->id, "Stripe refund {$refund->status}: ".($refund->failure_reason ?? 'no reason given')),
            // pending / requires_action (common for FPX): the outcome arrives as a refund.updated webhook.
            default => new RefundResult(RefundResult::PENDING, $refund->id),
        };
    }

    /**
     * Card subscription through Checkout in subscription mode. Stripe only
     * allows cards here: FPX is single-use, so it can't be charged again.
     * Stripe then charges the card every month and sends invoice.paid.
     */
    public function startCardSubscription(Subscription $subscription, string $returnUrl): CheckoutSession
    {
        $subscription->loadMissing('plan', 'user');

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'myr',
                    'unit_amount' => $subscription->price_sen,
                    'recurring' => ['interval' => $subscription->plan->interval],
                    'product_data' => ['name' => "SkillMatch {$subscription->plan->name}"],
                ],
            ]],
            'customer_email' => $subscription->user->email,
            'client_reference_id' => "subscription-{$subscription->id}",
            'metadata' => ['skillmatch_subscription_id' => (string) $subscription->id],
            // Copied onto the Stripe subscription, so every renewal invoice names ours.
            'subscription_data' => ['metadata' => ['skillmatch_subscription_id' => (string) $subscription->id]],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ], ['idempotency_key' => "subscription-{$subscription->id}-{$subscription->created_at?->timestamp}"]);

        return new CheckoutSession($session->id, $session->url);
    }

    /** One period's invoice, paid at a one-off Checkout (FPX or card, as enabled in the dashboard). */
    public function checkoutInvoice(SubscriptionInvoice $invoice, string $returnUrl): CheckoutSession
    {
        $invoice->loadMissing('subscription.plan', 'subscription.user');
        $subscription = $invoice->subscription;

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'myr',
                    'unit_amount' => $invoice->amount_sen,
                    'product_data' => ['name' => "SkillMatch {$subscription->plan->name}, {$invoice->period_start->format('j M Y')} to {$invoice->period_end->format('j M Y')}"],
                ],
            ]],
            'customer_email' => $subscription->user->email,
            'client_reference_id' => "subscription-invoice-{$invoice->id}",
            'metadata' => ['skillmatch_subscription_invoice_id' => (string) $invoice->id],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ], ['idempotency_key' => "subscription-invoice-{$invoice->id}-{$invoice->created_at?->timestamp}"]);

        return new CheckoutSession($session->id, $session->url);
    }

    public function cancelAtPeriodEnd(Subscription $subscription): void
    {
        if ($subscription->gateway_subscription_id) {
            $this->stripe->subscriptions->update($subscription->gateway_subscription_id, ['cancel_at_period_end' => true]);
        }
    }

    /** Subscription events, or null when the event is about an order payment, refund or dispute. */
    private function subscriptionWebhook(string $type, string $eventId, array $object, array $payload): ?WebhookResult
    {
        $kind = $object['object'] ?? null;

        // Checkout for a SkillMatch renewal invoice (FPX or card, one-off).
        if ($kind === 'checkout.session' && isset($object['metadata']['skillmatch_subscription_invoice_id'])) {
            return new WebhookResult(
                reference: (string) $object['id'],
                status: match ($type) {
                    'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid' ? WebhookResult::PAID : WebhookResult::PENDING,
                    'checkout.session.async_payment_succeeded' => WebhookResult::PAID,
                    'checkout.session.async_payment_failed', 'checkout.session.expired' => WebhookResult::FAILED,
                    default => WebhookResult::PENDING,
                },
                amountSen: isset($object['amount_total']) ? (int) $object['amount_total'] : null,
                eventKey: $eventId,
                payload: $payload,
                kind: WebhookResult::KIND_SUBSCRIPTION,
                ownReference: (string) $object['metadata']['skillmatch_subscription_invoice_id'],
                subject: 'invoice',
            );
        }

        // Checkout that started a card subscription.
        if ($kind === 'checkout.session' && ($object['mode'] ?? null) === 'subscription') {
            $paid = $type === 'checkout.session.completed' && in_array($object['payment_status'] ?? null, ['paid', 'no_payment_required'], true);

            return new WebhookResult(
                reference: (string) $object['id'],
                status: $paid ? WebhookResult::PAID : ($type === 'checkout.session.expired' ? WebhookResult::FAILED : WebhookResult::PENDING),
                amountSen: isset($object['amount_total']) ? (int) $object['amount_total'] : null,
                eventKey: $eventId,
                payload: $payload,
                kind: WebhookResult::KIND_SUBSCRIPTION,
                gatewayPaymentId: is_string($object['subscription'] ?? null) ? $object['subscription'] : null,
                ownReference: isset($object['metadata']['skillmatch_subscription_id']) ? (string) $object['metadata']['skillmatch_subscription_id'] : null,
                subject: 'checkout',
            );
        }

        // Stripe charged (or failed to charge) a card subscription for a period.
        if ($kind === 'invoice' && in_array($type, ['invoice.paid', 'invoice.payment_failed'], true)) {
            // Newer API versions nest the subscription under parent.subscription_details.
            $subscriptionId = data_get($object, 'parent.subscription_details.subscription') ?? data_get($object, 'subscription');
            $ours = data_get($object, 'parent.subscription_details.metadata.skillmatch_subscription_id')
                ?? data_get($object, 'subscription_details.metadata.skillmatch_subscription_id');

            if (! $subscriptionId && ! $ours) {
                return null; // not a subscription invoice
            }

            return new WebhookResult(
                reference: (string) $object['id'],
                status: $type === 'invoice.paid' ? WebhookResult::PAID : WebhookResult::FAILED,
                amountSen: (int) ($object['amount_paid'] ?? 0),
                eventKey: $eventId,
                payload: $payload,
                kind: WebhookResult::KIND_SUBSCRIPTION,
                gatewayPaymentId: is_string($subscriptionId) ? $subscriptionId : null,
                ownReference: $ours !== null ? (string) $ours : null,
                subject: 'renewal',
                // The line's period is the period paid for (the invoice's own period_* fields are not).
                periodStart: data_get($object, 'lines.data.0.period.start'),
                periodEnd: data_get($object, 'lines.data.0.period.end'),
            );
        }

        if ($kind === 'subscription' && $type === 'customer.subscription.deleted') {
            return new WebhookResult(
                reference: (string) $object['id'],
                status: WebhookResult::CANCELLED,
                amountSen: null,
                eventKey: $eventId,
                payload: $payload,
                kind: WebhookResult::KIND_SUBSCRIPTION,
                gatewayPaymentId: (string) $object['id'],
                ownReference: isset($object['metadata']['skillmatch_subscription_id']) ? (string) $object['metadata']['skillmatch_subscription_id'] : null,
                subject: 'subscription',
            );
        }

        return null;
    }

    /** Stripe's fee lives on the charge's balance transaction. */
    public function processingFee(Payment $payment): ?int
    {
        $session = $this->stripe->checkout->sessions->retrieve($payment->gateway_reference, [
            'expand' => ['payment_intent.latest_charge.balance_transaction'],
        ]);

        $balanceTransaction = $session->payment_intent?->latest_charge?->balance_transaction;

        // Not created yet for some async methods; the caller asks again later.
        return is_object($balanceTransaction) ? (int) $balanceTransaction->fee : null;
    }
}

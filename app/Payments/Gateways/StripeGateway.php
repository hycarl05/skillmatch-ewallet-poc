<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\CheckoutSession;
use App\Payments\InvalidWebhookSignature;
use App\Payments\PaymentGateway;
use App\Payments\PaymentMethodUsed;
use App\Payments\RefundResult;
use App\Payments\ReportsPaymentMethod;
use App\Payments\ReportsProcessingFee;
use App\Payments\WebhookResult;
use Illuminate\Http\Request;
use Stripe\Charge;
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
class StripeGateway implements PaymentGateway, ReportsPaymentMethod, ReportsProcessingFee
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

    /** Stripe's fee lives on the charge's balance transaction. */
    public function processingFee(Payment $payment): ?int
    {
        $balanceTransaction = $this->charge($payment)?->balance_transaction;

        // Not created yet for some async methods; the caller asks again later.
        return is_object($balanceTransaction) ? (int) $balanceTransaction->fee : null;
    }

    /** FPX requires showing the bank and FPX transaction id on the confirmation page. */
    public function paymentMethod(Payment $payment): ?PaymentMethodUsed
    {
        $charge = $this->charge($payment);
        $method = $charge?->payment_method_details;
        if (! is_object($method) || ! is_string($method->type ?? null)) {
            return null;
        }

        $specifics = $method->{$method->type} ?? null;

        return new PaymentMethodUsed(
            method: $method->type,
            chargeId: (string) $charge->id,
            details: match ($method->type) {
                'fpx' => ['bank' => $specifics?->bank ?? null, 'transaction_id' => $specifics?->transaction_id ?? null],
                'card' => ['brand' => $specifics?->brand ?? null, 'last4' => $specifics?->last4 ?? null],
                default => [],
            },
        );
    }

    /** @var array<int, Charge|null> per payment id, so the fee and method share one API call */
    private array $charges = [];

    private function charge(Payment $payment): ?Charge
    {
        if (! array_key_exists($payment->id, $this->charges)) {
            $session = $this->stripe->checkout->sessions->retrieve($payment->gateway_reference, [
                'expand' => ['payment_intent.latest_charge.balance_transaction'],
            ]);
            $charge = $session->payment_intent?->latest_charge;
            // Not cached while missing: an async payment's charge appears later.
            if (! $charge instanceof Charge) {
                return null;
            }
            $this->charges[$payment->id] = $charge;
        }

        return $this->charges[$payment->id];
    }
}

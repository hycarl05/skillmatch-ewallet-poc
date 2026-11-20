<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\CheckoutSession;
use App\Payments\InvalidWebhookSignature;
use App\Payments\PaymentGateway;
use App\Payments\RefundResult;
use App\Payments\ReportsProcessingFee;
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
class StripeGateway implements PaymentGateway, ReportsProcessingFee
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
        );
    }

    public function refund(Payment $payment, int $amountSen): RefundResult
    {
        $payment->loadMissing('order');

        try {
            $session = $this->stripe->checkout->sessions->retrieve($payment->gateway_reference);

            $refund = $this->stripe->refunds->create([
                'payment_intent' => $session->payment_intent,
                'amount' => $amountSen,
                'metadata' => ['payment_id' => (string) $payment->id],
            ], ['idempotency_key' => "refund-{$payment->order->reference}-{$payment->id}-{$amountSen}"]);
        } catch (ApiErrorException $e) {
            return new RefundResult(RefundResult::MANUAL_REQUIRED, message: 'Stripe refund failed: '.$e->getMessage());
        }

        if (in_array($refund->status, ['failed', 'canceled'], true)) {
            return new RefundResult(RefundResult::MANUAL_REQUIRED, $refund->id, "Stripe refund {$refund->status}");
        }

        // "pending" refunds (common for FPX) complete on their own.
        return new RefundResult(RefundResult::SUCCEEDED, $refund->id);
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

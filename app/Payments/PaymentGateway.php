<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Every payment gateway (Billplz, CHIP, the local simulator...) implements
 * this. The rest of the app never talks to a gateway directly, so switching
 * gateway is a config change.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Create a bill / purchase at the gateway and return where to send the buyer. */
    public function createCheckout(Order $order, Payment $payment, string $callbackUrl, string $returnUrl): CheckoutSession;

    /**
     * Verify the webhook's signature and turn it into a WebhookResult.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): WebhookResult;

    public function refund(Payment $payment, int $amountSen): RefundResult;
}

<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\CheckoutSession;
use App\Payments\InvalidWebhookSignature;
use App\Payments\PaymentGateway;
use App\Payments\RefundResult;
use App\Payments\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A local payment-gateway simulator. It behaves like a real gateway (hosted
 * payment page, signed webhooks, refunds) so the whole flow can be built and
 * demonstrated without any sandbox account.
 */
class FakeGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Fake-Signature';

    public function name(): string
    {
        return 'fake';
    }

    public function createCheckout(Order $order, Payment $payment, string $callbackUrl, string $returnUrl): CheckoutSession
    {
        return new CheckoutSession(
            'fake_'.Str::lower(Str::random(14)),
            route('fake-gateway.show', ['payment' => $payment->id]),
        );
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        $body = $request->getContent();
        $signature = (string) $request->header(self::SIGNATURE_HEADER);

        if (! hash_equals($this->sign($body), $signature)) {
            throw new InvalidWebhookSignature('Fake gateway signature mismatch');
        }

        $data = json_decode($body, true) ?: [];

        return new WebhookResult(
            reference: (string) ($data['reference'] ?? ''),
            status: match ($data['status'] ?? null) {
                'paid' => WebhookResult::PAID,
                'failed' => WebhookResult::FAILED,
                default => WebhookResult::PENDING,
            },
            amountSen: isset($data['amount_sen']) ? (int) $data['amount_sen'] : null,
            eventKey: (string) ($data['event_id'] ?? ''),
            payload: $data,
        );
    }

    public function refund(Payment $payment, int $amountSen): RefundResult
    {
        return new RefundResult(RefundResult::SUCCEEDED, 'fake_refund_'.Str::lower(Str::random(10)));
    }

    /** Build the webhook request this "gateway" would send for a payment. */
    public function webhookRequest(Payment $payment, string $status, ?string $eventId = null): Request
    {
        $body = json_encode([
            'event_id' => $eventId ?? 'evt_'.Str::lower(Str::random(16)),
            'reference' => $payment->gateway_reference,
            'status' => $status,
            'amount_sen' => $payment->amount_sen,
        ]);

        return Request::create(
            route('webhooks.handle', ['gateway' => 'fake']),
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_'.str_replace('-', '_', strtoupper(self::SIGNATURE_HEADER)) => $this->sign($body)],
            content: $body,
        );
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('skillmatch.gateways.fake.secret'));
    }
}

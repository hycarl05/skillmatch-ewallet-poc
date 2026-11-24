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
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Billplz (API v3). Sandbox: https://www.billplz-sandbox.com
 *
 * Split payment is set up on the Billplz *collection* (in the dashboard),
 * so Billplz pays the trainer and SkillMatch at collection time. That is
 * why this gateway only supports the "instant" payout mode.
 */
class BillplzGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'billplz';
    }

    public function createCheckout(Order $order, Payment $payment, string $callbackUrl, string $returnUrl): CheckoutSession
    {
        $config = $this->config();
        $order->loadMissing('buyer', 'training');

        $response = Http::asForm()
            ->withBasicAuth($config['api_key'], '')
            ->post($this->baseUrl().'/bills', [
                'collection_id' => $config['collection_id'],
                'email' => $order->buyer->email,
                'name' => $order->buyer->name,
                'amount' => $payment->amount_sen, // Billplz uses sen
                'description' => mb_substr("SkillMatch {$order->reference}: {$order->training->title}", 0, 200),
                'callback_url' => $callbackUrl,
                'redirect_url' => $returnUrl,
                'reference_1_label' => 'Order',
                'reference_1' => $order->reference,
            ])
            ->throw()
            ->json();

        return new CheckoutSession($response['id'], $response['url']);
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        $data = $request->request->all();

        if (! hash_equals(self::signature($data, (string) $this->config()['x_signature']), (string) ($data['x_signature'] ?? ''))) {
            throw new InvalidWebhookSignature('Billplz X-Signature mismatch');
        }

        $paid = ($data['paid'] ?? '') === 'true';

        return new WebhookResult(
            reference: (string) ($data['id'] ?? ''),
            status: $paid ? WebhookResult::PAID : (($data['state'] ?? '') === 'due' ? WebhookResult::PENDING : WebhookResult::FAILED),
            amountSen: $paid && isset($data['paid_amount']) ? (int) $data['paid_amount'] : null,
            eventKey: implode(':', [$data['id'] ?? '', $data['state'] ?? '', $data['paid_at'] ?? '']),
            payload: $data,
        );
    }

    public function refund(Payment $payment, int $amountSen, string $idempotencyKey, array $metadata = []): RefundResult
    {
        // FPX payments are not reversed automatically. Billplz refunds are done
        // by sending the money back with a Payment Order (or in the dashboard).
        return new RefundResult(
            RefundResult::MANUAL_REQUIRED,
            message: 'Billplz does not auto-reverse FPX. Send the refund with a Payment Order or from the Billplz dashboard.',
        );
    }

    /**
     * Billplz X-Signature: every field except x_signature as "keyvalue",
     * sorted ascending (case-insensitive), joined with "|", then HMAC-SHA256.
     */
    public static function signature(array $data, string $key): string
    {
        unset($data['x_signature']);

        $parts = [];
        foreach ($data as $field => $value) {
            $parts[] = $field.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
        usort($parts, 'strcasecmp');

        return hash_hmac('sha256', implode('|', $parts), $key);
    }

    private function baseUrl(): string
    {
        return $this->config()['sandbox']
            ? 'https://www.billplz-sandbox.com/api/v3'
            : 'https://www.billplz.com/api/v3';
    }

    private function config(): array
    {
        $config = config('skillmatch.gateways.billplz');

        foreach (['api_key', 'x_signature', 'collection_id'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException("Billplz is not configured: set BILLPLZ_".strtoupper($key)." in .env");
            }
        }

        return $config;
    }
}

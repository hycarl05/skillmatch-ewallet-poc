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
 * CHIP Collect. Use test keys from the CHIP portal for sandbox testing.
 *
 * Money lands in SkillMatch's CHIP balance and trainers are paid later
 * (deferred mode). Paying them would use CHIP Send, which needs a CHIP Send
 * account opened by CHIP; until then payouts go through the fake provider.
 * Note: in this model the money sits in SkillMatch's balance for a while,
 * which the company must be comfortable with.
 */
class ChipGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'chip';
    }

    public function createCheckout(Order $order, Payment $payment, string $callbackUrl, string $returnUrl): CheckoutSession
    {
        $config = $this->config();
        $order->loadMissing('buyer', 'training');

        $response = Http::withToken($config['secret_key'])
            ->acceptJson()
            ->post(rtrim($config['base_url'], '/').'/purchases/', [
                'brand_id' => $config['brand_id'],
                'reference' => $order->reference,
                'client' => ['email' => $order->buyer->email, 'full_name' => $order->buyer->name],
                'purchase' => [
                    'currency' => 'MYR',
                    'products' => [['name' => mb_substr($order->training->title, 0, 256), 'price' => $payment->amount_sen]],
                ],
                'success_callback' => $callbackUrl,
                'success_redirect' => $returnUrl,
                'failure_redirect' => $returnUrl,
                'cancel_redirect' => $returnUrl,
            ])
            ->throw()
            ->json();

        return new CheckoutSession($response['id'], $response['checkout_url']);
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        $body = $request->getContent();
        $signature = base64_decode((string) $request->header('X-Signature'), true);
        $publicKey = (string) config('skillmatch.gateways.chip.public_key');

        if (! $signature || ! $publicKey || openssl_verify($body, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidWebhookSignature('CHIP signature mismatch');
        }

        $data = json_decode($body, true) ?: [];
        $status = $data['status'] ?? '';

        return new WebhookResult(
            reference: (string) ($data['id'] ?? ''),
            status: match ($status) {
                'paid' => WebhookResult::PAID,
                'error', 'cancelled', 'expired', 'blocked' => WebhookResult::FAILED,
                default => WebhookResult::PENDING,
            },
            amountSen: isset($data['purchase']['total']) ? (int) $data['purchase']['total'] : null,
            eventKey: ($data['id'] ?? '').':'.$status,
            payload: $data,
        );
    }

    public function refund(Payment $payment, int $amountSen): RefundResult
    {
        $config = $this->config();

        $response = Http::withToken($config['secret_key'])
            ->acceptJson()
            ->post(rtrim($config['base_url'], '/')."/purchases/{$payment->gateway_reference}/refund/", ['amount' => $amountSen]);

        if ($response->failed()) {
            return new RefundResult(RefundResult::MANUAL_REQUIRED, message: 'CHIP refund failed: '.$response->body());
        }

        return new RefundResult(RefundResult::SUCCEEDED, $response->json('id'));
    }

    private function config(): array
    {
        $config = config('skillmatch.gateways.chip');

        foreach (['brand_id', 'secret_key'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException('CHIP is not configured: set CHIP_'.strtoupper($key).' in .env');
            }
        }

        return $config;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\PayoutMode;
use App\Models\Payment;
use App\Models\Training;
use App\Models\User;
use App\Payments\Gateways\StripeGateway;
use App\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The Stripe API calls: what the Checkout session is created with, and how
 * the charge is read back. Stripe's HTTP client is swapped for one that
 * records the request and answers with $response, so nothing leaves the machine.
 */
class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $sent = [];

    /** @var array<string, mixed> what the stubbed Stripe API answers */
    private array $response = ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        ApiRequestor::setHttpClient(new class($this->sent, $this->response) implements ClientInterface
        {
            public function __construct(private array &$sent, private array &$response) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->sent = $params;

                return [json_encode($this->response), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** Stripe shows FPX automatically (dynamic payment methods) when every line item is in MYR. */
    public function test_checkout_uses_dynamic_payment_methods_in_myr(): void
    {
        $session = $this->createCheckout();

        $this->assertArrayNotHasKey('payment_method_types', $this->sent);
        $this->assertSame('myr', $this->sent['line_items'][0]['price_data']['currency']);
        $this->assertSame('cs_test_1', $session->reference);
    }

    public function test_fpx_bank_and_transaction_id_are_read_from_the_charge(): void
    {
        $this->response = [
            'id' => 'cs_test_1',
            'object' => 'checkout.session',
            'payment_intent' => [
                'id' => 'pi_test_1',
                'object' => 'payment_intent',
                'latest_charge' => [
                    'id' => 'ch_test_1',
                    'object' => 'charge',
                    'balance_transaction' => ['id' => 'txn_test_1', 'object' => 'balance_transaction', 'fee' => 135],
                    'payment_method_details' => [
                        'type' => 'fpx',
                        'fpx' => ['bank' => 'maybank2u', 'transaction_id' => '2611271234567890', 'account_holder_type' => 'individual'],
                    ],
                ],
            ],
        ];
        $gateway = new StripeGateway(new StripeClient('sk_test_dummy'));
        $payment = new Payment(['gateway_reference' => 'cs_test_1']);
        $payment->id = 1;

        $used = $gateway->paymentMethod($payment);

        $this->assertSame('fpx', $used->method);
        $this->assertSame('ch_test_1', $used->chargeId);
        $this->assertSame(['bank' => 'maybank2u', 'transaction_id' => '2611271234567890'], $used->details);
        $this->assertSame(135, $gateway->processingFee($payment));
        $this->assertSame(['payment_intent.latest_charge.balance_transaction'], $this->sent['expand']);
    }

    public function test_no_charge_yet_means_ask_again_later(): void
    {
        $this->response = ['id' => 'cs_test_1', 'object' => 'checkout.session', 'payment_intent' => null];
        $payment = new Payment(['gateway_reference' => 'cs_test_1']);
        $payment->id = 1;

        $this->assertNull((new StripeGateway(new StripeClient('sk_test_dummy')))->paymentMethod($payment));
    }

    private function createCheckout(): \App\Payments\CheckoutSession
    {
        $buyer = User::where('name', 'like', 'Aisyah%')->firstOrFail();
        $training = Training::where('title', 'like', 'Laravel for Beginners%')->firstOrFail();
        $order = app(OrderService::class)->create($buyer, $training, null, 0, 'fake', PayoutMode::Deferred)['order'];

        return (new StripeGateway(new StripeClient('sk_test_dummy')))
            ->createCheckout($order, $order->payments()->firstOrFail(), 'https://example.test/cb', 'https://example.test/return');
    }
}

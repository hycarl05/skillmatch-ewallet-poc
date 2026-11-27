<?php

namespace Tests\Feature\Concerns;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\SubscriptionInvoice;
use App\Models\Training;
use App\Models\User;
use App\Payments\Gateways\FakeGateway;
use Illuminate\Testing\TestResponse;

/** Shared helpers for tests that run on the demo data (DatabaseSeeder). */
trait DemoHelpers
{
    protected function user(string $namePrefix): User
    {
        return User::where('name', 'like', "{$namePrefix}%")->firstOrFail();
    }

    protected function as(User $user): static
    {
        return $this->withHeader('X-Demo-User', (string) $user->id);
    }

    protected function training(string $titlePrefix): Training
    {
        return Training::where('title', 'like', "{$titlePrefix}%")->firstOrFail();
    }

    protected function buy(User $buyer, string $titlePrefix, array $extra = []): Order
    {
        $response = $this->as($buyer)->postJson('/api/orders', $extra + [
            'training_id' => $this->training($titlePrefix)->id,
            'gateway' => 'fake',
            'payout_mode' => 'deferred',
        ])->assertCreated();

        return Order::with('payments', 'splits')->findOrFail($response->json('order.id'));
    }

    /** The FakePay webhook for an order's payment. */
    protected function payOrder(Order $order, string $status = 'paid'): TestResponse
    {
        $payment = $order->payments()->first();

        return $this->signedFakeWebhook([
            'event_id' => "evt_{$payment->id}_{$status}",
            'reference' => $payment->gateway_reference,
            'status' => $status,
            'amount_sen' => $payment->amount_sen,
        ]);
    }

    /** The FakePay webhook for a subscription invoice. */
    protected function payInvoice(SubscriptionInvoice $invoice, string $status = 'paid', ?int $amount = null): TestResponse
    {
        return $this->signedFakeWebhook([
            'type' => 'subscription_invoice',
            'event_id' => "evt_subinv_{$invoice->id}_{$status}_".uniqid(),
            'reference' => $invoice->gateway_reference,
            'invoice_id' => $invoice->id,
            'status' => $status,
            'amount_sen' => $amount ?? $invoice->amount_sen,
        ]);
    }

    protected function signedFakeWebhook(array $data): TestResponse
    {
        $body = json_encode($data);

        return $this->call('POST', '/api/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => app(FakeGateway::class)->sign($body),
        ], $body);
    }

    /** Trainer completes, buyer confirms: trainers are paid out (deferred mode). */
    protected function deliver(Order $order, User $trainer, User $buyer): void
    {
        $this->as($trainer)->postJson("/api/orders/{$order->id}/complete")->assertOk();
        $this->as($buyer)->postJson("/api/orders/{$order->id}/confirm")->assertOk();
    }

    protected function assertBooksBalance(): void
    {
        $this->assertSame((int) LedgerEntry::sum('debit_sen'), (int) LedgerEntry::sum('credit_sen'), 'ledger debits must equal credits');
    }
}

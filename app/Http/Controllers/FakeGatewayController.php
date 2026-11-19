<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\Gateways\FakeGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The hosted payment page of the local gateway simulator. Its buttons make
 * the simulator send a signed webhook, exactly like a real gateway would.
 */
class FakeGatewayController extends Controller
{
    public function show(Payment $payment)
    {
        abort_unless($payment->gateway === 'fake', 404);

        return view('fake-gateway', ['payment' => $payment->load('order.training', 'order.buyer')]);
    }

    public function complete(Request $request, Payment $payment, FakeGateway $gateway)
    {
        abort_unless($payment->gateway === 'fake', 404);
        $outcome = $request->validate(['outcome' => ['required', 'in:paid,failed']])['outcome'];

        $eventId = 'evt_'.$payment->id.'_'.$outcome;
        $send = fn () => App::call(WebhookController::class, [
            'gateway' => 'fake',
            'request' => $gateway->webhookRequest($payment, $outcome, $eventId),
        ]);

        $send();
        if ($request->boolean('duplicate')) {
            $send(); // gateways retry webhooks: show that the second one is ignored
        }

        return redirect()->route('payment.return', ['payment' => $payment->id]);
    }
}

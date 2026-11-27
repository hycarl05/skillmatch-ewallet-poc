<?php

namespace App\Http\Controllers;

use App\Models\Payment;

/**
 * Where the gateway sends the buyer's browser after paying. This does NOT
 * mark anything as paid (query strings can be faked); it only shows the
 * order, whose status comes from the webhook.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(Payment $payment)
    {
        // Demo only: `as` switches "Acting as" back to the buyer, in case the
        // demo user (shared by all tabs) changed during checkout. Real auth
        // replaces this; DemoUser already refuses to run in production.
        $buyerId = $payment->order()->value('buyer_id');

        return redirect("/orders/{$payment->order_id}?returned=1&as={$buyerId}");
    }
}

<?php

namespace App\Payments;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;

/**
 * A gateway that can collect subscription fees (config skillmatch.subscriptions.gateways).
 *
 * Two ways to collect, because FPX is single-use and can't be charged again
 * automatically:
 *  - card:    the gateway stores the card and charges it every period (Stripe Billing).
 *  - invoice: SkillMatch issues an invoice each period, paid at a one-off checkout (FPX or card).
 */
interface SubscriptionGateway
{
    /** Start a card subscription the gateway renews by itself. */
    public function startCardSubscription(Subscription $subscription, string $returnUrl): CheckoutSession;

    /** A one-off checkout for one period's invoice. */
    public function checkoutInvoice(SubscriptionInvoice $invoice, string $returnUrl): CheckoutSession;

    /** Stop a gateway-renewed subscription at the end of the paid period. */
    public function cancelAtPeriodEnd(Subscription $subscription): void;
}

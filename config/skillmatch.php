<?php

/*
|--------------------------------------------------------------------------
| SkillMatch e-wallet PoC settings
|--------------------------------------------------------------------------
|
| All money is stored as integer sen (RM1.00 = 100). Never use floats.
|
*/

return [

    // Fallback commission, in percent of the training price, used only when no
    // row in the commission_rules table matches the order (see CommissionRules).
    'platform_fee_percent' => (float) env('SKILLMATCH_PLATFORM_FEE_PERCENT', 10),

    'commission' => [
        // Seed the client's commission table (Revenue Breakdown sheet) with the
        // demo data. The original flow tests turn this off (phpunit.xml) because
        // their amounts are worked out with the flat fallback rate.
        'seed_client_rules' => (bool) env('SKILLMATCH_SEED_CLIENT_RULES', true),
    ],

    'subscriptions' => [
        // Gateways that can collect subscription fees. Stripe: card subscriptions
        // (Stripe Billing) or invoices paid by FPX/card. FakePay: invoices; its
        // "card" renewals are charged automatically by the renewal job.
        'gateways' => ['fake', 'stripe'],
        // Renewal invoices go out this many days before the period ends.
        'renew_notice_days' => (int) env('SUBSCRIPTION_RENEW_NOTICE_DAYS', 7),
        // A renewal unpaid at the period end keeps the plan on this much longer.
        'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
    ],

    'skillpoints' => [
        // How many sen one SkillPoint is worth as a discount. 1 = 100 points per RM1.
        'sen_per_point' => (int) env('SKILLPOINTS_SEN_PER_POINT', 1),

        // Points earned per RM1 paid, credited when the buyer confirms the training.
        'earn_per_ringgit' => (int) env('SKILLPOINTS_EARN_PER_RINGGIT', 1),

        // Design decision for the PoC: the SkillPoints discount is paid out of
        // SkillMatch's commission, so the trainer's share never shrinks.
        // That means the discount can never be bigger than the platform fee.
    ],

    // Days after the trainer marks a training complete before the order is
    // confirmed automatically (deferred payout mode only).
    'auto_confirm_days' => (int) env('SKILLMATCH_AUTO_CONFIRM_DAYS', 3),

    'refunds' => [
        // How long after confirming a training the buyer can still ask for a
        // refund. Before confirmation they can always ask. SkillMatch approves
        // every request, and can refund at any time itself.
        'request_window_days' => (int) env('SKILLMATCH_REFUND_WINDOW_DAYS', 14),

        // The most a buyer can ask back, in % of what they paid, by how far the
        // order has gone (refunds already made count towards it). SkillMatch
        // can still refund more itself, e.g. when the trainer was at fault.
        'buyer_max_percent' => [
            'paid' => (int) env('SKILLMATCH_REFUND_MAX_BEFORE_COMPLETION', 100),
            'completed' => (int) env('SKILLMATCH_REFUND_MAX_AFTER_COMPLETION', 50),
            'confirmed' => (int) env('SKILLMATCH_REFUND_MAX_AFTER_COMPLETION', 50),
        ],

        // The gateway keeps its processing fee when money is refunded. Each
        // refund carries its share of that fee (fee x refunded / paid), paid by:
        //   buyer      - changed their mind: deducted from what they get back
        //   trainer    - trainer cancelled or didn't deliver: owed by the
        //                trainer, kept from their next payout
        //   skillmatch - goodwill: SkillMatch absorbs it
        'fee_paid_by' => ['buyer', 'trainer', 'skillmatch'],
    ],

    // Gateway used when the checkout request doesn't name one.
    'default_gateway' => env('PAYMENT_GATEWAY', 'fake'),

    /*
    | Payout modes
    | - instant:  the gateway splits the payment at collection time and pays
    |             trainers straight away (Billplz / ToyyibPay split payment).
    | - deferred: the trainer's share is held until the training is completed
    |             and confirmed, then paid out (Stripe Connect / Xendit
    |             xenPlatform / CHIP Collect + Send style).
    */
    'gateways' => [
        'fake' => [
            'class' => App\Payments\Gateways\FakeGateway::class,
            'payout_modes' => ['instant', 'deferred'], // the simulator can show both
            'secret' => env('FAKE_GATEWAY_SECRET', 'local-fake-gateway-secret'),
            // Simulated processing fee, so fee rules can be tried locally.
            'fee_percent' => (float) env('FAKE_GATEWAY_FEE_PERCENT', 3),
            'fee_fixed_sen' => (int) env('FAKE_GATEWAY_FEE_FIXED_SEN', 100),
        ],
        'billplz' => [
            'class' => App\Payments\Gateways\BillplzGateway::class,
            'payout_modes' => ['instant'], // split is configured on the collection, done at collection time
            'sandbox' => (bool) env('BILLPLZ_SANDBOX', true),
            'api_key' => env('BILLPLZ_API_KEY'),
            'x_signature' => env('BILLPLZ_X_SIGNATURE'),
            'collection_id' => env('BILLPLZ_COLLECTION_ID'),
        ],
        'chip' => [
            'class' => App\Payments\Gateways\ChipGateway::class,
            'payout_modes' => ['deferred'], // collect into SkillMatch's CHIP balance, pay out later with CHIP Send
            'base_url' => env('CHIP_BASE_URL', 'https://gate.chip-in.asia/api/v1'),
            'brand_id' => env('CHIP_BRAND_ID'),
            'secret_key' => env('CHIP_SECRET_KEY'),
            'public_key' => env('CHIP_PUBLIC_KEY'), // PEM, for verifying callback signatures
        ],
        'stripe' => [
            'class' => App\Payments\Gateways\StripeGateway::class,
            'payout_modes' => ['deferred'], // separate charges and transfers: held in SkillMatch's Stripe balance until confirmed
            'payout_provider' => 'stripe',  // Connect transfers to the trainer's Express account
            'publishable_key' => env('STRIPE_KEY'),
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
    ],

    // Payout provider used to pay trainers in deferred mode.
    'payout_provider' => env('PAYOUT_PROVIDER', 'fake'),

];

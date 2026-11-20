<?php

namespace App\Payments;

use App\Enums\PayoutMode;
use App\Payments\Payouts\FakePayoutProvider;
use App\Payments\Payouts\StripeConnectPayoutProvider;
use InvalidArgumentException;

class GatewayManager
{
    public function gateway(string $name): PaymentGateway
    {
        $class = config("skillmatch.gateways.{$name}.class");

        if (! $class) {
            throw new InvalidArgumentException("Unknown payment gateway '{$name}'");
        }

        return app($class);
    }

    /** @return string[] */
    public function available(): array
    {
        return array_keys(config('skillmatch.gateways'));
    }

    /** @return PayoutMode[] */
    public function payoutModes(string $name): array
    {
        return array_map(
            fn (string $mode) => PayoutMode::from($mode),
            config("skillmatch.gateways.{$name}.payout_modes", []),
        );
    }

    /** A gateway can name its own payout provider (Stripe → Connect transfers); otherwise the global one is used. */
    public function payoutProvider(?string $gateway = null): PayoutProvider
    {
        $name = ($gateway ? config("skillmatch.gateways.{$gateway}.payout_provider") : null)
            ?? config('skillmatch.payout_provider');

        return match ($name) {
            'fake' => app(FakePayoutProvider::class),
            'stripe' => app(StripeConnectPayoutProvider::class),
            default => throw new InvalidArgumentException('Unknown payout provider'),
        };
    }
}

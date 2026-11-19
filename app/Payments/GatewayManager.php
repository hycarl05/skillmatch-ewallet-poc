<?php

namespace App\Payments;

use App\Enums\PayoutMode;
use App\Payments\Payouts\FakePayoutProvider;
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

    public function payoutProvider(): PayoutProvider
    {
        return match (config('skillmatch.payout_provider')) {
            'fake' => app(FakePayoutProvider::class),
            default => throw new InvalidArgumentException('Unknown payout provider'),
        };
    }
}

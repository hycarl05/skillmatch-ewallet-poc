<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, function () {
            $key = config('skillmatch.gateways.stripe.secret_key');

            if (empty($key)) {
                throw new RuntimeException('Stripe is not configured: set STRIPE_SECRET_KEY in .env');
            }

            return new StripeClient(['api_key' => $key, 'max_network_retries' => 2]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

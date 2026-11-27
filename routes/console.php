<?php

use App\Services\OrderService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('orders:auto-confirm', function (OrderService $orders) {
    $count = $orders->autoConfirmDue();
    $this->info("Auto-confirmed {$count} order(s).");
})->purpose('Confirm completed trainings the buyer has not confirmed within the auto-confirm window');

Schedule::command('orders:auto-confirm')->hourly();

Artisan::command('subscriptions:renew', function (SubscriptionService $subscriptions) {
    $counts = $subscriptions->runRenewals();
    $this->info(collect($counts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));
})->purpose('Issue renewal invoices, mark unpaid subscriptions past due, and end them after the grace period');

Schedule::command('subscriptions:renew')->hourly();

<?php

use App\Services\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('orders:auto-confirm', function (OrderService $orders) {
    $count = $orders->autoConfirmDue();
    $this->info("Auto-confirmed {$count} order(s).");
})->purpose('Confirm completed trainings the buyer has not confirmed within the auto-confirm window');

Schedule::command('orders:auto-confirm')->hourly();

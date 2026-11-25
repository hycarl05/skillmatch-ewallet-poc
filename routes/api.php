<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\StripeConnectController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\DemoUser;
use Illuminate\Support\Facades\Route;

// Gateways call this. No user session: the signature is the authentication.
Route::post('webhooks/{gateway}', WebhookController::class)->name('webhooks.handle');

Route::get('demo/users', [DemoController::class, 'users']);
Route::get('config', [DemoController::class, 'config']);

Route::middleware(DemoUser::class)->group(function () {
    Route::get('trainings', [TrainingController::class, 'index']);
    Route::get('trainings/{training}/quote', [TrainingController::class, 'quote']);

    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/complete', [OrderController::class, 'complete']);
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('orders/{order}/refund-requests', [RefundController::class, 'request']);
    Route::post('orders/{order}/refunds', [RefundController::class, 'store']);
    Route::post('refunds/{refund}/approve', [RefundController::class, 'approve']);
    Route::post('refunds/{refund}/reject', [RefundController::class, 'reject']);
    Route::post('refunds/{refund}/retry', [RefundController::class, 'retry']);
    Route::post('refunds/{refund}/mark-sent', [RefundController::class, 'markSent']);
    Route::get('platform/refunds', [RefundController::class, 'queue']);

    Route::get('wallet', [WalletController::class, 'show']);
    Route::get('stripe/connect', [StripeConnectController::class, 'status']);
    Route::post('stripe/connect', [StripeConnectController::class, 'onboard']);
    Route::get('platform/ledger', [PlatformController::class, 'ledger']);
    Route::get('platform/dashboard', [DashboardController::class, 'show']);
});

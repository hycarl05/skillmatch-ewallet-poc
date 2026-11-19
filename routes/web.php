<?php

use App\Http\Controllers\FakeGatewayController;
use App\Http\Controllers\PaymentReturnController;
use Illuminate\Support\Facades\Route;

// Local gateway simulator's hosted payment page.
Route::get('fake-gateway/{payment}', [FakeGatewayController::class, 'show'])->name('fake-gateway.show');
Route::post('fake-gateway/{payment}', [FakeGatewayController::class, 'complete'])->name('fake-gateway.complete');

// Where gateways send the buyer's browser back to.
Route::get('payment/return/{payment}', PaymentReturnController::class)->name('payment.return');

// Everything else is the React app.
Route::view('/{any?}', 'app')->where('any', '(?!api/|up$).*');

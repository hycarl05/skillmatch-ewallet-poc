<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // How the buyer paid (card, fpx, ...). Null = not fetched from the gateway yet.
            $table->string('payment_method', 32)->nullable()->after('gateway_fee_sen');
            // The gateway's charge id (Stripe ch_...), shown on the FPX confirmation.
            $table->string('gateway_charge_id')->nullable()->after('payment_method');
            // Method specifics for the receipt: FPX bank + transaction id, card brand + last 4.
            $table->json('payment_method_details')->nullable()->after('gateway_charge_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'gateway_charge_id', 'payment_method_details']);
        });
    }
};

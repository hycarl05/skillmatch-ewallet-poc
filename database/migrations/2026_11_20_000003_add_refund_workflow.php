<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            // buyer (request) | platform (admin refund) | chargeback (lost dispute)
            $table->string('source')->default('platform')->after('payment_id');
            $table->foreignId('requested_by')->nullable()->after('source')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->after('requested_by')->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_reason')->nullable();
            // How the amount was split across the order's shares, and per-share clawback progress.
            $table->json('allocation')->nullable();
            // Where the money left from: {gateway_clearing: sen, platform_bank: sen}.
            $table->json('funding')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::table('order_splits', function (Blueprint $table) {
            $table->unsignedBigInteger('refunded_sen')->default(0)->after('amount_sen');
        });

        Schema::table('payments', function (Blueprint $table) {
            // The gateway's id for the actual charge (Stripe PaymentIntent), used to match disputes.
            $table->string('gateway_payment_id')->nullable()->after('gateway_reference')->index();
            $table->string('dispute_reference')->nullable();
            $table->string('dispute_status')->nullable(); // open | won | lost
            $table->string('dispute_reason')->nullable();
            $table->unsignedBigInteger('dispute_amount_sen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway_payment_id']);
            $table->dropColumn(['gateway_payment_id', 'dispute_reference', 'dispute_status', 'dispute_reason', 'dispute_amount_sen']);
        });

        Schema::table('order_splits', function (Blueprint $table) {
            $table->dropColumn('refunded_sen');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['source', 'reviewed_at', 'review_note', 'attempts', 'failure_reason', 'allocation', 'funding', 'completed_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            // The part of the buyer's payment being refunded. amount_sen is what the
            // buyer actually gets: gross minus the processing fee when they pay it.
            $table->unsignedBigInteger('gross_sen')->nullable()->after('amount_sen');
            // The gateway fee that belongs to this refund, and who pays it: buyer | trainer | skillmatch.
            $table->unsignedBigInteger('fee_sen')->default(0)->after('gross_sen');
            $table->string('fee_paid_by')->nullable()->after('fee_sen');
        });

        DB::table('refunds')->update(['gross_sen' => DB::raw('amount_sen'), 'fee_paid_by' => 'skillmatch']);

        Schema::table('order_splits', function (Blueprint $table) {
            // Kept from this payout to settle refund fees the trainer owed.
            $table->unsignedBigInteger('payout_offset_sen')->default(0)->after('payout_reference');
        });
    }

    public function down(): void
    {
        Schema::table('order_splits', function (Blueprint $table) {
            $table->dropColumn('payout_offset_sen');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['gross_sen', 'fee_sen', 'fee_paid_by']);
        });
    }
};

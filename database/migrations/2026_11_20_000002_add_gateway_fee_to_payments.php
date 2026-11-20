<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // What the gateway charged for processing, in sen. Null = not known (yet).
            $table->unsignedInteger('gateway_fee_sen')->nullable()->after('amount_sen');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('gateway_fee_sen');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The client's commission table (Revenue Breakdown sheet), as data.
        // The most specific active rule that matches an order wins (priority).
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('buyer_role')->nullable();            // App\Enums\UserRole value; null = any buyer
            $table->boolean('hrdc')->nullable();                 // true = HRDC trainings, false = non-HRDC, null = both
            $table->boolean('requires_subscription')->default(false); // buyer needs an active subscription
            $table->unsignedBigInteger('min_amount_sen')->default(0);
            $table->unsignedInteger('platform_bps');             // SkillMatch's share, basis points of the price (2000 = 20%)
            $table->unsignedInteger('buyer_discount_bps')->default(0); // discount the buyer gets, basis points of the price
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->date('effective_from')->nullable();
            $table->timestamps();
        });

        Schema::table('trainings', function (Blueprint $table) {
            $table->boolean('hrdc_claimable')->default(false)->after('price_sen');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('state')->nullable()->after('organisation'); // Malaysian state, for the Demand Marketplace
        });

        Schema::table('orders', function (Blueprint $table) {
            // platform = paid through a gateway; hrdc = HRDC pays the provider directly
            $table->string('funding')->default('platform')->after('gateway');
            $table->foreignId('commission_rule_id')->nullable()->after('platform_fee_sen')->constrained();
            $table->unsignedInteger('commission_bps')->nullable()->after('commission_rule_id'); // rate used, frozen on the order
            $table->unsignedBigInteger('company_discount_sen')->default(0)->after('discount_sen');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_rule_id');
            $table->dropColumn(['funding', 'commission_bps', 'company_discount_sen']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('state'));
        Schema::table('trainings', fn (Blueprint $table) => $table->dropColumn('hrdc_claimable'));
        Schema::dropIfExists('commission_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What can be bought (Tier Plan sheet). Prices are data, not code.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();      // e.g. provider-t1
            $table->string('name');
            $table->string('audience');            // App\Enums\UserRole value: company_hr | provider | freelancer
            $table->unsignedSmallInteger('tier');
            $table->unsignedBigInteger('price_sen');
            $table->string('interval')->default('month');
            $table->unsignedInteger('min_staff')->nullable(); // company plans: size band
            $table->unsignedInteger('max_staff')->nullable();
            $table->json('features');              // what the plan unlocks, e.g. {"demand_marketplace":"state"}
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->string('status')->index();     // pending | active | past_due | cancelled | expired
            $table->string('gateway');
            $table->string('collection');          // card (gateway renews automatically) | invoice (pay each period, e.g. FPX)
            $table->string('gateway_subscription_id')->nullable()->unique(); // Stripe sub_...
            $table->unsignedBigInteger('price_sen'); // price frozen when subscribing
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // One per billing period: what was charged, and the receipt number.
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('number')->nullable()->unique(); // given when paid, e.g. SUB-2026-000012
            $table->unsignedBigInteger('amount_sen');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('status');              // open | paid | failed | void
            $table->string('gateway');
            $table->string('gateway_reference')->nullable(); // checkout session or Stripe invoice id
            $table->string('checkout_url', 1024)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_reference']);
        });

        // Demand Marketplace: individuals and companies ask for a training.
        Schema::create('training_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('state');
            $table->unsignedBigInteger('budget_sen')->nullable();
            $table->unsignedInteger('participants')->default(1);
            $table->string('status')->default('open'); // open | closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_requests');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};

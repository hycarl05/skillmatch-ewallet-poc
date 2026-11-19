<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users'); // training provider or freelance trainer
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_sen');
            // Optional: a provider's training delivered by a hired freelance trainer.
            // The freelancer's share is split out of the provider's share (3-way split).
            $table->foreignId('freelancer_id')->nullable()->constrained('users');
            $table->unsignedBigInteger('freelancer_share_sen')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('beneficiary_id')->nullable()->constrained('users'); // staff member a company pays for
            $table->foreignId('training_id')->constrained();
            $table->string('status')->index();       // App\Enums\OrderStatus
            $table->string('payout_mode');           // App\Enums\PayoutMode
            $table->string('gateway');
            $table->unsignedBigInteger('gross_sen');        // training price
            $table->unsignedInteger('skillpoints_used')->default(0);
            $table->unsignedBigInteger('discount_sen')->default(0);
            $table->unsignedBigInteger('amount_sen');       // what the buyer actually pays
            $table->unsignedBigInteger('platform_fee_sen'); // SkillMatch commission before the discount
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users'); // null = SkillMatch
            $table->string('role');   // App\Enums\SplitRole
            $table->unsignedBigInteger('amount_sen');
            $table->string('status'); // App\Enums\SplitStatus
            $table->string('payout_reference')->nullable();
            $table->timestamp('paid_out_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->string('gateway_reference')->nullable(); // bill / purchase id at the gateway
            $table->string('checkout_url', 1024)->nullable();
            $table->unsignedBigInteger('amount_sen');
            $table->string('status'); // pending | paid | failed | refunded
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_reference']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained();
            $table->unsignedBigInteger('amount_sen');
            $table->string('status'); // succeeded | manual_required
            $table->string('gateway_reference')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        // Every webhook we receive, so the same event is never processed twice.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->string('event_key');
            $table->json('payload');
            $table->string('result')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_key']);
        });

        // Double-entry ledger: a record of where the money is. SkillMatch never
        // holds buyer money; these accounts describe money sitting at the gateway.
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. gateway_clearing, held:12, payable:12
            $table->string('name');
            $table->string('type'); // asset | liability | revenue | expense
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->string('type'); // payment | release | payout | refund ...
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained();
            $table->unsignedBigInteger('debit_sen')->default(0);
            $table->unsignedBigInteger('credit_sen')->default(0);
            $table->timestamps();
        });

        // SkillPoints are a discount, not money: they can't be withdrawn or transferred.
        Schema::create('skillpoint_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->integer('points'); // + earned / restored, - spent
            $table->string('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'skillpoint_transactions', 'ledger_entries', 'ledger_transactions', 'ledger_accounts',
            'webhook_events', 'refunds', 'payments', 'order_splits', 'orders', 'trainings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

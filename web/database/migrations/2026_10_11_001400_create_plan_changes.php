<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change the plan, settle early, pay across contracts (Win Plan PP6).

        // Money taken off a contract without being paid: the early-settlement rebate. Never edited; voiding the payment
        // that settled the contract writes the same amount negated (reverses_adjustment_id).
        Schema::create('contract_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24); // early_rebate
            $table->decimal('amount', 18, 4);
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reverses_adjustment_id')->nullable()->unique()->constrained('contract_adjustments')->nullOnDelete();
            $table->json('details')->nullable(); // the instalments it waived, with what each still owed
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'contract_id']);
        });

        // A change to the plan, with the schedule before and after, why, and who confirmed it.
        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24); // reschedule | skip | move
            $table->string('reason', 500);
            $table->json('old_schedule');
            $table->json('new_schedule');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'contract_id']);
        });

        Schema::table('installments', function (Blueprint $table) {
            // The plan change that wrote this instalment, or that set it aside.
            $table->foreignUuid('amendment_id')->nullable()->constrained('contract_amendments')->nullOnDelete();
        });

        // One payment from a customer spread over several of their contracts: one line on each, tied together here.
        Schema::create('payment_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('method', 24);
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('batch_id')->nullable()->constrained('payment_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });
        Schema::dropIfExists('payment_batches');
        Schema::table('installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amendment_id');
        });
        Schema::dropIfExists('contract_amendments');
        Schema::dropIfExists('contract_adjustments');
    }
};

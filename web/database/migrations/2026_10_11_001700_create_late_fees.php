<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Late fees (Win Plan K4, CT20).

        // The workspace's late-fee policy as it stood when the contract was made; null means the contract has no fees.
        Schema::table('contracts', function (Blueprint $table) {
            $table->json('late_fee_policy')->nullable();
        });

        // One fee for one period an instalment was late, charged once (unique instalment and period). Never added to the
        // instalment: it is its own line, paid by payments (late_fee_allocations) or waived with a reason.
        Schema::create('late_fees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained();
            $table->foreignUuid('installment_id')->constrained();
            $table->unsignedInteger('period');
            $table->date('charged_on');
            $table->decimal('amount', 18, 4);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->string('status', 8)->default('open'); // open | paid | waived
            $table->boolean('charity')->default(false);
            $table->text('waived_reason')->nullable();
            $table->foreignUuid('waived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();

            $table->unique(['installment_id', 'period']);
            $table->index(['tenant_id', 'contract_id', 'status']);
        });

        // How much of a payment went to one fee (negative for its void). Immutable, like transaction_allocations.
        Schema::create('late_fee_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained();
            $table->foreignUuid('late_fee_id')->constrained();
            $table->decimal('amount', 18, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index('late_fee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_fee_allocations');
        Schema::dropIfExists('late_fees');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('late_fee_policy');
        });
    }
};

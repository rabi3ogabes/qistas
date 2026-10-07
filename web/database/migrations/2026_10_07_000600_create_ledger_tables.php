<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger is append-only. Amounts are signed (payments +, reversals -), so SUM(amount) is the net.
     * Corrections are new rows, never edits; the Supabase migration backs this with triggers (see supabase/).
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained();
            $table->foreignUuid('customer_id')->constrained();
            $table->string('type', 16);   // payment | down_payment | reversal
            $table->string('method', 16); // cash | bank_transfer | card | cheque | other
            $table->decimal('amount', 18, 4);
            $table->timestamp('paid_at');
            $table->text('note')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->uuid('reverses_transaction_id')->nullable(); // foreign key added below, once the table has its key
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // A retried request with the same key must find the first one, never add a second.
            $table->unique(['tenant_id', 'idempotency_key']);
            // A payment can be reversed once.
            $table->unique('reverses_transaction_id');
            $table->index(['tenant_id', 'paid_at']);
            $table->index('contract_id');
        });

        // A reversal points back at the payment it undoes. Added after create(): PostgreSQL adds a table's primary key
        // after the constraints written inside create(), and a foreign key to the table's own key needs that key first.
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('reverses_transaction_id')->references('id')->on('transactions');
        });

        // Which instalments each transaction paid (reversals carry the same amounts, negated), so that an
        // instalment's paid_amount is always the sum of its allocations.
        Schema::create('transaction_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained();
            $table->foreignUuid('installment_id')->constrained();
            $table->decimal('amount', 18, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index('installment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_allocations');
        Schema::dropIfExists('transactions');
    }
};

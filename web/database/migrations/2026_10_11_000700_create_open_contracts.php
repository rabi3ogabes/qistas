<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Win Plan PP4: open contracts, a running tab with no schedule. A contract's type may now be `open`, with an optional
 * credit limit that warns and never refuses. "They took" is a `charge` line in the money ledger (its reversal a
 * `charge_reversal`), so a tab is as permanent as a payment; any line may carry a tag. When a scheduled or cash contract
 * becomes open, its unpaid instalments are `superseded` (kept, no longer owed) and the conversion is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('credit_limit', 18, 4)->nullable();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('tag', 16)->nullable(); // advance | refund | early_discount | unpaid
        });

        // "superseded" does not fit the old eight characters.
        Schema::table('installments', function (Blueprint $table) {
            $table->string('status', 16)->default('pending')->change();
        });

        Schema::create('contract_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained();
            $table->string('from_type', 16);
            $table->unsignedSmallInteger('superseded_count');
            $table->decimal('opening_amount', 18, 4);
            // The opening line, when something was left to carry over.
            $table->foreignUuid('transaction_id')->nullable()->constrained();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_conversions');
        Schema::table('installments', function (Blueprint $table) {
            $table->string('status', 8)->default('pending')->change();
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('tag');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });
    }
};

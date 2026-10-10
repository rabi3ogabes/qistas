<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Win Plan PP3: who funds the business. Every workspace has one main investor (its own capital), made the first
 * time it is needed; partners can be added. Each investor's money is an append-only ledger like `transactions`:
 * amounts are signed for the investor's wallet (deposits and money coming back +, withdrawals and funding -), so
 * SUM(amount) is the wallet. A mistake is reversed, never edited. Contracts made before this have no investor until
 * the main investor takes them on, with their history (App\Domain\Investors\MainInvestor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('commercial_registration', 60)->nullable();
            $table->char('currency', 3);
            $table->boolean('is_main')->default(false);
            // A partner's share of its own profit that goes to the main investor.
            $table->decimal('commission_percent', 7, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'archived_at']);
        });

        // One main investor per workspace, however many requests ask for it at once.
        DB::statement('create unique index investors_one_main on investors (tenant_id) where is_main = true');

        Schema::create('investor_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('investor_id')->constrained();
            // deposit | withdrawal | funding_out | funding_back | principal_back | profit_share | commission
            $table->string('type', 16);
            $table->decimal('amount', 18, 4);
            $table->foreignUuid('contract_id')->nullable()->constrained();
            $table->foreignUuid('transaction_id')->nullable()->constrained();
            $table->uuid('reverses_entry_id')->nullable(); // foreign key added below, once the table has its key
            $table->text('note')->nullable();
            $table->date('occurred_on');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('reverses_entry_id');
            // A payment credits each investor once per kind of entry, however often the books are brought up to date.
            $table->unique(['transaction_id', 'investor_id', 'type']);
            $table->index(['tenant_id', 'investor_id', 'occurred_on']);
            $table->index('contract_id');
        });

        Schema::table('investor_entries', function (Blueprint $table) {
            $table->foreign('reverses_entry_id')->references('id')->on('investor_entries');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignUuid('investor_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investor_id');
        });
        Schema::dropIfExists('investor_entries');
        Schema::dropIfExists('investors');
    }
};

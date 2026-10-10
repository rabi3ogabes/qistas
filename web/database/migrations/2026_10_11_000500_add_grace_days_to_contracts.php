<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Win Plan PP5: a few days' grace before an instalment counts as late. The grace is fixed when the contract is made,
 * so each instalment keeps the last day of its grace (`grace_until` = due date + grace days) and every lateness check reads
 * that one column, the same on SQLite and PostgreSQL. Existing instalments get no grace: late the day after they are due, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_days')->default(0);
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->date('grace_until')->nullable();
        });

        DB::table('installments')->whereNull('grace_until')->update(['grace_until' => DB::raw('due_date')]);

        Schema::table('installments', function (Blueprint $table) {
            $table->index(['contract_id', 'grace_until']);
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'grace_until']);
            $table->dropColumn('grace_until');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('grace_days');
        });
    }
};

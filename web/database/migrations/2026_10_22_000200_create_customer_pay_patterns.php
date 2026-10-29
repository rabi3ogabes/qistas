<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payday learning (daily-habit wave A, H6): the day of the month a customer really pays on, worked out from the
        // dates of their payments (App\Domain\PayPattern\PayPatternCalculator). One row per customer, replaced each time
        // it is worked out again; a customer with too little to say has no row. It is a figure derived from the ledger,
        // never an input to it: nothing here is money, and it can be rebuilt from the payments at any time.
        Schema::create('customer_pay_patterns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day'); // 1 to 28; 28 means the end of the month (the 28th to the 31st)
            $table->unsignedTinyInteger('spread'); // Q3 less Q1 of the days, in days
            $table->unsignedTinyInteger('months'); // how many months it was worked out from (3 to 12)
            $table->unsignedTinyInteger('within'); // percent of those months paid within 3 days of `day`
            $table->string('confidence', 6); // high | medium
            $table->timestamp('computed_at');

            $table->unique(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_pay_patterns');
    }
};

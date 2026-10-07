<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // What the plan costs, in the plan's own currency. Null = not for sale at a listed price.
            $table->decimal('price_monthly', 18, 4)->nullable()->after('description');
            $table->decimal('price_yearly', 18, 4)->nullable()->after('price_monthly');
            $table->string('currency', 3)->default('USD')->after('price_yearly');
        });

        // Starting prices for the built-in plans; the admin console edits them.
        DB::table('plans')->where('key', 'free')->update(['price_monthly' => 0, 'price_yearly' => 0]);
        DB::table('plans')->where('key', 'pro')->update(['price_monthly' => 12, 'price_yearly' => 120]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_monthly', 'price_yearly', 'currency']);
        });
    }
};

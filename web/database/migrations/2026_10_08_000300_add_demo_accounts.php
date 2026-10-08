<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Try the demo": each press of a demo button makes a throw-away account and workspace with sample data.
        // The account carries the moment it expires; once that has passed it and everything it owns are deleted.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('demo_expires_at')->nullable()->index()->after('current_tenant_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('demo_expires_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A test workspace is a sandbox with sample data that a platform admin opens to try the product. It is a
        // real workspace in every other respect, so everything behaves exactly as for a customer, and it is marked
        // so that nothing counts it as a customer.
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('status');
        });

        // Which of their workspaces a person is working in. Null means the oldest one. Only the test tools set it.
        // No foreign key on purpose: a deleted workspace simply stops matching and the oldest one is used again.
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('current_tenant_id')->nullable()->after('platform_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('current_tenant_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};

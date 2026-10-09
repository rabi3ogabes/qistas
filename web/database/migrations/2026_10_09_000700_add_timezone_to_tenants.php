<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The workspace's own clock (an IANA name such as Asia/Riyadh), so that scheduled work means the shop's
        // morning. Null for workspaces made before this existed: they take their country's zone (Tenant::localTimezone).
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};

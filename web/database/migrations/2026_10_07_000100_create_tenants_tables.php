<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('country', 2);
            $table->string('currency', 3);
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('active'); // active | suspended
            $table->timestamps();
        });

        // A user may belong to several workspaces; the role is per membership.
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // owner | manager | accountant | collector | viewer (App\Tenancy\TenantRole)
            $table->timestamps();
            $table->primary(['tenant_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};

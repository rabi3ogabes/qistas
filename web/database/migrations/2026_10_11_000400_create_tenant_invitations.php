<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Win Plan PP11: the owner (or a manager) invites people into the business with a link. Only a fingerprint (SHA-256)
 * of the link's secret is stored; a link works once, for 7 days, and can be revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('name', 120)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'accepted_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
    }
};

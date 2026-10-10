<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Win Plan PP1: deleting an account from inside the app, as Google Play and Apple require. An owner's request turns the
 * business read-only until `delete_after`, when everything is erased; `account_deletions` keeps the bare fact that a
 * deletion was asked for and done (ids only, no personal detail, no foreign keys, since what it points at is erased).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('delete_after')->nullable()->index();
        });

        Schema::create('account_deletions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->nullable();
            $table->string('scope', 16); // workspace | login
            $table->timestamp('requested_at');
            $table->timestamp('delete_after')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletions');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['delete_after']);
            $table->dropColumn(['deletion_requested_at', 'delete_after']);
        });
    }
};

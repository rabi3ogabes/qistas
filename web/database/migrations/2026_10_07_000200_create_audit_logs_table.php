<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only. No foreign keys on purpose: the trail must outlive the rows it describes.
     * The Supabase migration additionally revokes UPDATE/DELETE and adds a trigger (see supabase/).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

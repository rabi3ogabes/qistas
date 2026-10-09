<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The record of each file kept in private storage. The file itself lives in the `files` disk under
        // tenants/{tenant_id}/{kind}/{id}.{ext}; this row says whose it is, what it belongs to and what it is.
        Schema::create('stored_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('path', 255);
            $table->string('mime', 64);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('subject_type', 120);
            $table->uuid('subject_id');
            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['tenant_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};

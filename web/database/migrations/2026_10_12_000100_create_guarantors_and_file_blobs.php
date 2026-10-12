<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarantors and contract photos (Win Plan K5, CT21, CT22).

        // The private `files` disk kept in the database (decision D5), for a host with no lasting disk. Rows are storage,
        // not records: they are found by path, the same path a bucket would use, and only Files writes them. The
        // bytes are base64 text, so every database driver keeps them the same way.
        Schema::create('file_blobs', function (Blueprint $table) {
            $table->string('path', 512)->primary();
            $table->longText('contents');
            $table->unsignedBigInteger('size');
            $table->string('mime', 100);
            $table->timestamps();
        });

        // Up to three people who stand behind a contract. The ID number is encrypted (the model's cast) and only ever
        // shown masked; collectors and viewers never see it.
        Schema::create('guarantors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained();
            $table->unsignedTinyInteger('position'); // the order they were added in, so lists never shuffle
            $table->string('name');
            $table->string('phone', 32);
            $table->string('relation', 60)->nullable();
            $table->text('national_id')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'contract_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantors');
        Schema::dropIfExists('file_blobs');
    }
};

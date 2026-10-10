<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every statement, report and receipt a workspace made (Win Plan PP8): what it was, what it was about, the code its
        // QR carries to /verify, and what that page may show (a reference and a total, never a person). The same document
        // asked for again within ten minutes is found by its params hash and is not counted twice. The PDF itself is made
        // afresh from the ledger each time, so nothing personal sits in storage.
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('subject_type', 120)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('params_hash', 64);
            $table->string('verification_code', 16)->unique();
            $table->string('reference', 120);
            $table->decimal('total', 18, 4)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'kind', 'params_hash', 'created_at']);
        });

        // The shop's logo and signature for its documents. Kept in the database, re-encoded and scaled down, because the
        // host's disk is thrown away and no bucket is set up (the same reason as appearance_assets). One of each.
        Schema::create('business_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 16);
            $table->string('mime', 24);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->longText('data'); // base64
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_assets');
        Schema::dropIfExists('documents');
    }
};

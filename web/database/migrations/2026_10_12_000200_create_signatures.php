<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The customer's signature (Win Plan K5, CT23, decision D6).

        // A link the customer signs through on their own phone: it works once, for a day, and only with the six-digit
        // code sent to them apart. Only hashes are kept; the link and the code are shown to the owner once.
        Schema::create('signature_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained();
            $table->char('token_hash', 64)->unique();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'contract_id']);
        });

        // Each time the customer signed, on the owner's screen or through a link; the latest is the contract's. The
        // picture is a PNG drawn again by the server, as base64 (a few KB), kept like the business's own signature.
        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained();
            $table->string('name');
            $table->longText('image');
            $table->string('method', 8); // screen | link
            $table->foreignUuid('signature_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'contract_id', 'signed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_signatures');
        Schema::dropIfExists('signature_links');
    }
};

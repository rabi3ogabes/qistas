<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buy Pro your way (Win Plan PP13).

        // Where the plan came from and, for a store, what the store says about it. Only a hash of the purchase token is
        // kept: enough to recognise it when the store writes, never enough to use it.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('source', 16)->nullable(); // play | appstore | web | manual | admin; null = sign-up or demo
            $table->string('store_product_id', 100)->nullable();
            $table->string('purchase_token_hash', 64)->nullable()->unique();
            $table->boolean('auto_renew')->nullable();
            $table->string('store_status', 32)->nullable(); // the store's own word, as it last said it
        });

        // Every message a store sent, once: a repeat of the same event id changes nothing.
        Schema::create('store_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 16); // google_play
            $table->string('event_id', 128);
            $table->json('payload');
            $table->string('outcome', 32)->nullable(); // what it did: renewed, expired, refunded, ignored...
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'event_id']);
        });

        // A bank transfer or wallet payment the owner reports with its receipt; the Qistas team approves or rejects it.
        Schema::create('manual_payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained();
            $table->string('period', 8); // month | year
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3);
            $table->string('method', 16); // bank_transfer | wallet
            $table->string('reference', 100);
            $table->string('status', 16)->default('pending'); // pending | approved | rejected
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
        });

        // The receipt that goes with a request, kept in the database (re-encoded if a picture): the app's file storage is
        // not permanent on every host, and the team must be able to open it for as long as the request is kept.
        Schema::create('manual_payment_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('manual_payment_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mime', 32);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->longText('data'); // base64
            $table->timestamp('created_at')->useCurrent();
        });

        // The platform's own settings (billing switches, bank details, store keys). Secrets are stored encrypted.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('manual_payment_receipts');
        Schema::dropIfExists('manual_payment_requests');
        Schema::dropIfExists('store_events');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['purchase_token_hash']);
            $table->dropColumn(['source', 'store_product_id', 'purchase_token_hash', 'auto_renew', 'store_status']);
        });
    }
};

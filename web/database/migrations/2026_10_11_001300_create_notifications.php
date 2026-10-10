<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instalment alerts and the morning summary (Win Plan PP9).

        // The phones that receive pushes. A phone's token is unique: whoever signs in on it last receives its pushes.
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 10); // android | ios | web
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
        });

        // Each person's choices, per kind of alert and channel; a kind with no row has its default.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('channel', 16)->default('push');
            $table->boolean('enabled');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'type', 'channel']);
        });

        // Every alert decided, once: the dedupe key makes a second run of the same minute, or a retried one, a no-op.
        Schema::create('notification_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('channel', 16);
            $table->string('status', 16); // sent | skipped
            $table->string('dedupe_key', 191)->unique();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['tenant_id', 'subject_id']);
        });

        // A business's own reminder wording, per language. The default wording lives in the translations, so a
        // business without a row uses it in every language.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('language', 5);
            $table->text('body');
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'language']);
        });

        // The in-app inbox: what each person was told, newest first.
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 200);
            $table->string('body', 1000);
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('notification_log');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('push_tokens');
    }
};

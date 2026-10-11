<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Help inside the app (Win Plan PP17).

        // A question or a problem someone sent from the app, with the app version and platform (never a password).
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 16); // question | problem | missing_data | billing | idea
            $table->text('message');
            $table->string('language', 5)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('platform', 16)->nullable(); // android | ios | web
            $table->string('status', 16)->default('open'); // open | answered | closed
            $table->timestamp('first_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'user_id']);
        });

        // The Qistas team's answers to a ticket, in order.
        Schema::create('support_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete(); // the staff member
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });

        // "What's new" in each app version, in each language.
        Schema::create('release_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('version', 20);
            $table->string('language', 5);
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['version', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_notes');
        Schema::dropIfExists('support_replies');
        Schema::dropIfExists('support_tickets');
    }
};

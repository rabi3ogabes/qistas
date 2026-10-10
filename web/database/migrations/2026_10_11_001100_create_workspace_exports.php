<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A business's books taken out as one file (Win Plan PP10): the nightly copies (seven kept) and the downloads people
        // ask for (kept a day). The file is compressed and encrypted with the app key and kept here, because the host's
        // disk is thrown away and no bucket is set up; it is only ever handed out through a five-minute signed link.
        Schema::create('workspace_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);   // nightly | manual
            $table->string('format', 8);  // xlsx | csv
            $table->string('status', 16); // ready | failed
            $table->longText('payload')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->json('row_counts')->nullable();
            $table->string('language', 5);
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'kind', 'created_at']);
        });

        // The activity log reads one business's entries, newest first.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
        });
        Schema::dropIfExists('workspace_exports');
    }
};

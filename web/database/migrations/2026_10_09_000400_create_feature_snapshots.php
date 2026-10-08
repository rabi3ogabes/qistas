<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How the switchable features stood just before a preset was applied, so "restore previous" can put them back.
        Schema::create('feature_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('states');
            $table->string('preset', 32);
            $table->text('reason');
            $table->foreignUuid('taken_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_snapshots');
    }
};

<?php

use App\Entitlements\PlatformFeatures;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_features', function (Blueprint $table) {
            // The feature's own key (the Feature enum value) is the key: one row per feature, ever.
            $table->string('feature_key', 64)->primary();
            // New features ship dark; the existing core features are written as 'on' by the sync below.
            $table->string('state', 8)->default('off');
            $table->text('reason')->nullable();
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });

        // A row for every feature declared today, at its launch state (core features on).
        PlatformFeatures::sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_features');
    }
};

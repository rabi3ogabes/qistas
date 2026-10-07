<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false); // the plan every new workspace starts on
            $table->boolean('is_public')->default(true);   // listed on the pricing page
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // What each plan gets. No row for a feature means "use the code's default for this plan key";
        // for a plan the admin created and has not configured, that default is "not included".
        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->boolean('enabled');
            $table->unsignedInteger('limit_value')->nullable(); // null = unlimited
            $table->timestamps();
            $table->unique(['plan_id', 'feature_key']);
        });

        // One row per workspace; changing plan updates it in place.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained();
            $table->string('status', 16)->default('active'); // active | trialing | past_due | canceled | expired
            $table->timestamp('current_period_end')->nullable(); // null = does not lapse
            $table->timestamps();
        });

        // A hand-made exception for one workspace, with a reason and an optional end date.
        Schema::create('tenant_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->boolean('enabled');
            $table->unsignedInteger('limit_value')->nullable();
            $table->text('reason');
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'feature_key']);
        });

        // Monthly quotas (e.g. PDF statements). Counted features such as customers are counted live instead.
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->string('period', 7); // YYYY-MM
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'feature_key', 'period']);
        });

        // Reference data the application cannot start without: sign-up needs a default plan.
        $now = now();
        DB::table('plans')->insert([
            ['id' => (string) Str::uuid7(), 'key' => 'free', 'name' => 'Free', 'description' => null, 'is_default' => true, 'is_public' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid7(), 'key' => 'pro', 'name' => 'Pro', 'description' => null, 'is_default' => false, 'is_public' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('tenant_overrides');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};

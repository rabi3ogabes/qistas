<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How often each workspace used each feature on each day: counts only, never what was done. It feeds the
        // admin's "used by N workspaces in the last 30 days" badge, which decides whether switching a feature off
        // needs a reason.
        Schema::create('feature_usage_daily', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->date('day');
            $table->unsignedInteger('hits')->default(0);

            $table->primary(['tenant_id', 'feature_key', 'day']);
            $table->index(['feature_key', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usage_daily');
    }
};

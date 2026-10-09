<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per call of the cron endpoint: when it started and ended, how it went and how many queued jobs it
        // did. It is how the admin overview knows the scheduler is alive. Nothing here belongs to a workspace.
        Schema::create('cron_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('outcome', 16)->default('running');
            $table->unsignedInteger('jobs')->default(0);
            $table->text('error')->nullable();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_runs');
    }
};

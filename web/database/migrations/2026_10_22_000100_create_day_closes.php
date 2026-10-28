<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // End-of-day close (daily-habit wave A, H4): the evening ritual that sets the cash a person counted against what
        // Qistas recorded for the workspace's day, and keeps the summary that was signed off.

        // One row per close of one day. A close is never edited: reopening a day changes the row's status and records who
        // reopened it and why, and closing it again writes the next version (see App\Models\DayClose). The day is the
        // workspace's own calendar day; the money in `totals` is a snapshot of what DayTotals worked out when it was
        // closed, with a hash of that snapshot, so a later reading can tell whether the figures were touched.
        Schema::create('day_closes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 10)->default('closed'); // closed | reopened
            $table->decimal('expected_cash', 18, 4);
            $table->decimal('counted_cash', 18, 4);
            $table->decimal('difference', 18, 4); // counted less expected: short is negative, over is positive
            $table->json('totals'); // by_method, count, per_member
            $table->text('note')->nullable();
            // A person who leaves the team leaves the close as it was; only the name goes.
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->foreignUuid('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->char('snapshot_hash', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'day', 'version']);
            $table->index(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_closes');
    }
};

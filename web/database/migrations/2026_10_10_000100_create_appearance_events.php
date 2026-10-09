<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A seasonal look (a national day, Ramadan, White Friday): for some countries (none = everyone), from a first to a
        // last day in its own time zone, on some places. Laid over the published look while it lasts. Written only by
        // App\Theme\Appearance.
        Schema::create('appearance_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('preset', 40)->nullable();
            $table->json('countries');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('timezone', 64);
            $table->json('surfaces');
            $table->json('pins');
            $table->json('images');
            $table->json('banners');
            $table->string('status', 16)->default('draft'); // draft | scheduled
            $table->unsignedInteger('revision')->default(1); // bumped on every change, so its stylesheet address changes
            $table->json('repaired')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appearance_events');
    }
};

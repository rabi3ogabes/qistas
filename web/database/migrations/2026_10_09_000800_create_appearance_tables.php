<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The pictures an admin chooses for the brand (logo, hero, welcome banner). They are kept in the database,
        // already re-encoded and resized, because the host's disk is thrown away and no bucket is set up; they are
        // served from an address that never changes (see BrandAssetController). Nothing here belongs to a workspace.
        Schema::create('appearance_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slot', 16);
            $table->string('mime', 24);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->longText('data'); // base64
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // The look of the product. One row is the working draft; every other row is a published version that is never
        // changed again (history). The newest published row is what everyone sees.
        Schema::create('appearance_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version')->nullable()->unique(); // null for the draft
            $table->string('status', 12)->default('draft');
            $table->json('pins');       // the colours the admin chose: {light: {primary: '#...'}}
            $table->json('tokens')->nullable(); // the full resolved palette (published versions)
            $table->json('images');     // slot => asset id
            $table->json('banners');    // surface => banner
            $table->json('repaired')->nullable(); // what the contrast gate moved when it was published
            $table->string('note', 200)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appearance_versions');
        Schema::dropIfExists('appearance_assets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A person's own profile: a phone of their own (for text-message notices once a provider is chosen) and a
        // photo. The photo is a picture drawn again without its hidden data and kept small; it has its own table so the
        // user row every request reads stays light.
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
        });

        Schema::create('user_photos', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('mime', 32);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->longText('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_photos');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};

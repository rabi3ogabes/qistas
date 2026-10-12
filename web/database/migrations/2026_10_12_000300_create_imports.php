<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bringing a shop's books in (Win Plan K9, RP17, decision D8). One row per file: what was read, how its columns
        // are taken, the trial run, and once confirmed what was brought in. The cells and the planned rows can carry ID
        // numbers, so both are encrypted (the model's casts); the cells are dropped once the import is confirmed.
        Schema::create('imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source', 8); // xlsx | csv | ai
            $table->string('filename');
            $table->json('headers');
            $table->longText('cells');
            $table->json('mapping');
            $table->longText('rows');
            $table->json('summary');
            $table->string('status', 8)->default('trial'); // trial | done
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};

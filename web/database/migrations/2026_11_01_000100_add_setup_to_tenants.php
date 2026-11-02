<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Go-live basics (spec 2026-11-01 §3.2, §4.1, plan deviation D3). A new workspace starts with a placeholder name and a
| detected place, and its owner confirms both once on the first-run card: that moment is setup_confirmed_at. How the
| country was chosen is kept as well (header | language | default | chosen), so the card can say whether it was a guess.
|
| Every workspace that exists when this runs was set up by its owner by hand, so it counts as confirmed from the day it
| was made.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('setup_confirmed_at')->nullable();
            $table->string('country_source', 12)->nullable();
        });

        // One plain statement that SQLite (tests) and PostgreSQL (live) both run.
        DB::table('tenants')->update(['setup_confirmed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['setup_confirmed_at', 'country_source']);
        });
    }
};

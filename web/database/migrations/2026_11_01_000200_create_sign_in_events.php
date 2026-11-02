<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The sign-in record (go-live basics, Release 1): one row for every real sign-in and every wrong password for an
        // account that exists. It belongs to the person, not to a workspace (one person can belong to several), so there
        // is no tenant_id. Append-only: the model refuses updates and deletes, and only `qistas:sign-ins:prune` removes
        // rows, once they are older than `qistas.sign_in_days`. Deleting the person deletes their rows.
        //
        // Nothing a person typed is ever stored here: no e-mail, no phone, no password, no code. A failed attempt for an
        // account that does not exist is not recorded at all, so there is nobody to attach it to.
        Schema::create('sign_in_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 16); // password now; otp, google and microsoft arrive later
            $table->string('channel', 8); // web | app
            $table->string('outcome', 8); // success | failed
            $table->string('device_type', 8); // desktop | mobile | tablet | unknown
            $table->string('os', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('app_version', 16)->nullable(); // what the phone app says about itself; null on the web
            $table->string('device_name', 100)->nullable(); // the phone's model, as the app reports it; null on the web
            $table->string('ip', 45); // long enough for an IPv6 address
            $table->char('ip_country', 2)->nullable(); // from the CDN's header only; null when unknown
            $table->string('proxy', 12); // not_detected | possible | detected
            $table->json('proxy_signals'); // tor | via_header | forwarded_chain | timezone_country_mismatch
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at'); // there is no updated_at: a row is never changed

            $table->index(['user_id', 'created_at']); // a person's recent sign-ins, and the failed-attempt throttle
            $table->index('created_at'); // the prune command
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The error center (go-live package, Release 2): what is breaking on the site, the API, the database and the phone
        // app, grouped into issues. All five tables are platform-wide, like sign_in_events and audit_logs: platform code
        // writes them, so there is no tenant_id and no business name or id anywhere. Nothing a person typed, no address,
        // no user agent, no cookie and no request body is ever stored (ErrorScrubber cleans every string on the way in).
        // `App\Errors\ErrorRecorder` and `ErrorTriage` are the only writers.

        // One row per kind of failure, found by its fingerprint.
        Schema::create('error_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('fingerprint', 40)->unique(); // sha1(source | kind | class | normalised message | top frame)
            $table->string('source', 16); // site | api | database | app
            $table->string('kind', 16); // exception | http | slow_query | script | crash
            $table->string('title', 200);
            $table->string('class', 160)->nullable();
            $table->string('status', 16)->default('open'); // open | resolved | ignored
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->unsignedInteger('people')->default(0); // how many different people, never who; stops at 500
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('first_version', 16)->nullable();
            $table->string('last_version', 16)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable(); // a staff user's id; no foreign key, like audit_logs.user_id
            $table->timestamp('ignored_at')->nullable();
            $table->uuid('ignored_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopened_version', 16)->nullable(); // "came back in 1.30.0"
            $table->timestamp('alerted_at')->nullable(); // when the instant e-mail went
            $table->timestamps();

            $table->index(['status', 'last_seen_at']); // the list, newest first, and retention
            $table->index(['source', 'status']); // the source filter
        });

        // The last five sightings of an issue, scrubbed. Writing a sixth deletes the oldest.
        Schema::create('error_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('issue_id')->constrained('error_issues')->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->unsignedInteger('status')->nullable(); // the HTTP status, when there was one
            $table->string('method', 8)->nullable();
            $table->string('route', 160)->nullable(); // the route pattern, never the address with ids or a query string
            $table->string('message', 500); // scrubbed
            $table->text('trace'); // scrubbed, at most 8 KB; empty when there is none (an error page)
            $table->string('version', 16)->nullable();
            $table->string('platform', 8); // server | web | android | ios
            $table->string('device', 8)->nullable(); // desktop | mobile | tablet
            $table->timestamp('created_at'); // there is no updated_at: a row is never changed

            $table->index(['issue_id', 'occurred_at']); // a sample list, and "delete the oldest of this issue"
        });

        // How many times an issue happened each day: the sparkline and "growing".
        Schema::create('error_issue_days', function (Blueprint $table) {
            $table->foreignUuid('issue_id')->constrained('error_issues')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('count');

            $table->primary(['issue_id', 'day']);
        });

        // Who an issue touched, as 16 hex characters of an HMAC of the user id: enough to count people, not to name one.
        Schema::create('error_issue_people', function (Blueprint $table) {
            $table->foreignUuid('issue_id')->constrained('error_issues')->cascadeOnDelete();
            $table->char('person', 16);

            $table->primary(['issue_id', 'person']);
        });

        // Scanner requests (probes for /.env and the like) and reports over a flood cap: counted per day, never an issue.
        Schema::create('error_noise_days', function (Blueprint $table) {
            $table->date('day');
            $table->string('kind', 8); // scanner | dropped
            $table->unsignedInteger('count');

            $table->primary(['day', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_noise_days');
        Schema::dropIfExists('error_issue_people');
        Schema::dropIfExists('error_issue_days');
        Schema::dropIfExists('error_occurrences');
        Schema::dropIfExists('error_issues');
    }
};

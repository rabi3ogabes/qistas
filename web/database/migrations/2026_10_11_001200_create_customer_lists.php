<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lists that stay short (Win Plan PP12): pinned regulars on top, a "last activity" to sort by (kept as each
        // contract and payment is written, so sorting stays fast with hundreds of customers), and finished contracts
        // archived out of the way.
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->index(['tenant_id', 'pinned_at']);
            $table->index(['tenant_id', 'last_activity_at']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });

        // Tags group customers ("Shop 2", "Government staff"); a name is unique in a business whatever its case.
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('colour', 16)->default('grey');
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX tags_tenant_name_unique ON tags (tenant_id, LOWER(name))');

        Schema::create('customer_tags', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['customer_id', 'tag_id']);
            $table->index(['tenant_id', 'tag_id']);
        });

        // Customers who were here before: their last activity is their newest contract or payment, else when they were added.
        DB::table('customers')->update(['last_activity_at' => DB::raw('created_at')]);
        DB::statement('UPDATE customers SET last_activity_at = (SELECT MAX(contracts.created_at) FROM contracts WHERE contracts.customer_id = customers.id)
            WHERE EXISTS (SELECT 1 FROM contracts WHERE contracts.customer_id = customers.id AND contracts.created_at > customers.last_activity_at)');
        DB::statement('UPDATE customers SET last_activity_at = (SELECT MAX(transactions.created_at) FROM transactions JOIN contracts ON contracts.id = transactions.contract_id WHERE contracts.customer_id = customers.id)
            WHERE EXISTS (SELECT 1 FROM transactions JOIN contracts ON contracts.id = transactions.contract_id WHERE contracts.customer_id = customers.id AND transactions.created_at > customers.last_activity_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('tags');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'pinned_at']);
            $table->dropIndex(['tenant_id', 'last_activity_at']);
            $table->dropColumn(['pinned_at', 'last_activity_at']);
        });
    }
};

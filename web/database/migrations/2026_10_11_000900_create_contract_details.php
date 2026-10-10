<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Win Plan PP7 (and the discount at sale from PP6): what was sold, what it cost and the tax in its price, the shop's own
 * contract number (unique in the workspace whatever its case; C-0001 stays the default), a discount taken off the price
 * before the down payment, a simple list of products, and the customer's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('title', 120)->nullable();
            $table->string('own_reference', 40)->nullable();
            $table->decimal('cost_price', 18, 4)->nullable();
            $table->decimal('tax_percent', 7, 4)->nullable();
            $table->decimal('tax_amount', 18, 4)->nullable();
            $table->string('discount_type', 8)->default('none'); // none | fixed | percent
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
        });

        // "A-7" and "a-7" are the same number to the people who type them.
        DB::statement('create unique index contracts_tenant_own_reference on contracts (tenant_id, lower(own_reference))');

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('sku', 60)->nullable();
            $table->decimal('default_price', 18, 4)->nullable();
            $table->decimal('cost', 18, 4)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'archived_at']);
        });

        Schema::create('contract_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 120);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('serial', 60)->nullable();
            $table->decimal('cost', 18, 4)->nullable();
            $table->decimal('price', 18, 4)->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index(['tenant_id', 'serial']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('job', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('job'));
        Schema::dropIfExists('contract_items');
        Schema::dropIfExists('products');
        DB::statement('drop index contracts_tenant_own_reference');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['title', 'own_reference', 'cost_price', 'tax_percent', 'tax_amount', 'discount_type', 'discount_value', 'discount_amount']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            // A customer is only ever soft-deleted, so their contracts keep a valid reference.
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number'); // C-0001, per workspace
            $table->string('type', 16);        // scheduled | cash
            $table->string('status', 16)->default('active'); // active | settled | cancelled

            // Money is decimal(18,4) and handled as bcmath strings in PHP, never as floats.
            $table->decimal('principal', 18, 4);
            $table->decimal('down_payment', 18, 4)->default(0);
            $table->decimal('financed', 18, 4);       // principal - down_payment
            $table->string('markup_type', 8);         // none | fixed | percent
            $table->decimal('markup_value', 18, 4)->default(0);
            $table->decimal('markup_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4);          // financed + markup_amount; what the instalments add up to

            $table->unsignedSmallInteger('installment_count');
            $table->string('frequency', 16);          // weekly | biweekly | monthly
            $table->date('start_date');
            $table->date('first_due_date');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index('customer_id');
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('amount', 18, 4);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->string('status', 8)->default('pending'); // pending | partial | paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'number']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
        Schema::dropIfExists('contracts');
    }
};

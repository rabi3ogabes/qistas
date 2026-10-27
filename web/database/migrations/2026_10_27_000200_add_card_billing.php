<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pro by card (spec 2026-10-27 §5.1). A workspace's Stripe customer, the mode it was made in (a test customer is
| never used with live keys), and the day its one free trial was used, by whatever way it first paid. The workspace's
| subscription keeps the Stripe subscription it follows and the period it renews by.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_customer_id', 64)->nullable()->unique();
            $table->boolean('stripe_livemode')->nullable();
            $table->timestamp('trial_used_at')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id', 64)->nullable()->unique();
            $table->string('period', 8)->nullable(); // month | year
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['stripe_subscription_id']);
            $table->dropColumn(['stripe_subscription_id', 'period']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropColumn(['stripe_customer_id', 'stripe_livemode', 'trial_used_at']);
        });
    }
};

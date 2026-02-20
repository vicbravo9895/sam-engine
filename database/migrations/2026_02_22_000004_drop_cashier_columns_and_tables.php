<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove Stripe/Cashier tables and columns. Usage is tracked in usage_events
     * and usage_daily_summaries; pricing will be handled in-house later.
     */
    public function up(): void
    {
        if (Schema::hasTable('subscription_items')) {
            Schema::dropIfExists('subscription_items');
        }
        if (Schema::hasTable('subscriptions')) {
            Schema::dropIfExists('subscriptions');
        }

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                $columns = ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('companies', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index()->after('is_active');
            $table->string('pm_type')->nullable()->after('stripe_id');
            $table->string('pm_last_four', 4)->nullable()->after('pm_type');
            $table->timestamp('trial_ends_at')->nullable()->after('pm_last_four');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }
};

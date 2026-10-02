<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: bidder_price_items (آیتم‌های قیمت مناقصه‌گر)
     */
    public function up(): void
    {
        Schema::create('bidder_price_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bidder_id');
            $table->decimal('amount', 20, 2);
            $table->string('currency', 10)->default('IRR');
            $table->string('currency_name', 100)->nullable();
            $table->decimal('exchange_rate', 15, 4)->nullable();
            $table->decimal('amount_in_rials', 20, 2);
            $table->boolean('is_adjustable')->default(false);
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('bidder_id')
                ->references('id')
                ->on('bidders')
                ->onDelete('cascade');

            $table->index('bidder_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bidder_price_items');
    }
};


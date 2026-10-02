<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * اضافه کردن ستون is_adjustable به جدول bidder_price_items در صورت عدم وجود
     */
    public function up(): void
    {
        if (!Schema::hasColumn('bidder_price_items', 'is_adjustable')) {
            Schema::table('bidder_price_items', function (Blueprint $table) {
                $table->boolean('is_adjustable')->default(false)->after('amount_in_rials');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('bidder_price_items', 'is_adjustable')) {
            Schema::table('bidder_price_items', function (Blueprint $table) {
                $table->dropColumn('is_adjustable');
            });
        }
    }
};


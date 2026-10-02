<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * اضافه کردن ستون currency_name به جدول bidder_price_items در صورت عدم وجود
     */
    public function up(): void
    {
        if (!Schema::hasColumn('bidder_price_items', 'currency_name')) {
            Schema::table('bidder_price_items', function (Blueprint $table) {
                $table->string('currency_name', 100)->nullable()->after('currency');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('bidder_price_items', 'currency_name')) {
            Schema::table('bidder_price_items', function (Blueprint $table) {
                $table->dropColumn('currency_name');
            });
        }
    }
};


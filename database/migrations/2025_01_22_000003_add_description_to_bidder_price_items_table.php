<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * اضافه کردن ستون description به جدول bidder_price_items در صورت عدم وجود
     */
    public function up(): void
    {
        if (!Schema::hasColumn('bidder_price_items', 'description')) {
            Schema::table('bidder_price_items', function (Blueprint $table) {
                $table->text('description')->nullable()->after('is_adjustable');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('bidder_price_items', 'description')) {
            Schema::table('bidder_price_items', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * اضافه کردن ستون‌های is_winner_first و is_winner_second به جدول evaluation_results در صورت عدم وجود
     */
    public function up(): void
    {
        if (!Schema::hasColumn('evaluation_results', 'is_winner_first')) {
            Schema::table('evaluation_results', function (Blueprint $table) {
                $table->boolean('is_winner_first')->default(false)->after('is_winner');
            });
        }
        
        if (!Schema::hasColumn('evaluation_results', 'is_winner_second')) {
            Schema::table('evaluation_results', function (Blueprint $table) {
                $table->boolean('is_winner_second')->default(false)->after('is_winner_first');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('evaluation_results', 'is_winner_second')) {
            Schema::table('evaluation_results', function (Blueprint $table) {
                $table->dropColumn('is_winner_second');
            });
        }
        
        if (Schema::hasColumn('evaluation_results', 'is_winner_first')) {
            Schema::table('evaluation_results', function (Blueprint $table) {
                $table->dropColumn('is_winner_first');
            });
        }
    }
};


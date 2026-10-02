<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * تغییر نوع ستون final_score به decimal(30, 2) برای پشتیبانی از مقادیر بزرگ
     */
    public function up(): void
    {
        if (Schema::hasColumn('evaluation_results', 'final_score')) {
            // تغییر نوع ستون به decimal(30, 2)
            DB::statement('ALTER TABLE `evaluation_results` MODIFY `final_score` DECIMAL(30, 2) NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('evaluation_results', 'final_score')) {
            // برگرداندن به decimal(20, 2)
            DB::statement('ALTER TABLE `evaluation_results` MODIFY `final_score` DECIMAL(20, 2) NULL');
        }
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * تغییر ستون bidder_id به nullable برای پشتیبانی از رکوردهای summary
     */
    public function up(): void
    {
        if (Schema::hasColumn('evaluation_results', 'bidder_id')) {
            // پیدا کردن نام constraint
            $constraints = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'evaluation_results' 
                AND COLUMN_NAME = 'bidder_id' 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            // حذف foreign key constraint اگر وجود دارد
            if (!empty($constraints)) {
                $constraintName = $constraints[0]->CONSTRAINT_NAME;
                DB::statement("ALTER TABLE `evaluation_results` DROP FOREIGN KEY `{$constraintName}`");
            }
            
            // تغییر ستون به nullable
            DB::statement('ALTER TABLE `evaluation_results` MODIFY `bidder_id` CHAR(36) NULL');
            
            // اضافه کردن مجدد foreign key constraint
            if (!empty($constraints)) {
                DB::statement('ALTER TABLE `evaluation_results` ADD CONSTRAINT `evaluation_results_bidder_id_foreign` FOREIGN KEY (`bidder_id`) REFERENCES `bidders` (`id`) ON DELETE CASCADE');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('evaluation_results', 'bidder_id')) {
            // حذف foreign key constraint
            DB::statement('ALTER TABLE `evaluation_results` DROP FOREIGN KEY `evaluation_results_bidder_id_foreign`');
            
            // تغییر ستون به NOT NULL
            DB::statement('ALTER TABLE `evaluation_results` MODIFY `bidder_id` CHAR(36) NOT NULL');
            
            // اضافه کردن مجدد foreign key constraint
            DB::statement('ALTER TABLE `evaluation_results` ADD CONSTRAINT `evaluation_results_bidder_id_foreign` FOREIGN KEY (`bidder_id`) REFERENCES `bidders` (`id`) ON DELETE CASCADE');
        }
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * اضافه کردن indexes برای بهبود عملکرد جستجو و فیلتر
     */
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            // Index برای فیلدهای جستجو
            try {
                $table->index('title', 'tenders_title_index');
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
            
            try {
                $table->index('type', 'tenders_type_index');
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
            
            // Composite index برای فیلترهای رایج
            try {
                $table->index(['status', 'created_at'], 'tenders_status_created_index');
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
            
            try {
                $table->index(['type', 'status'], 'tenders_type_status_index');
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            try {
                $table->dropIndex('tenders_title_index');
            } catch (\Exception $e) {
                // Index may not exist, ignore error
            }
            
            try {
                $table->dropIndex('tenders_type_index');
            } catch (\Exception $e) {
                // Index may not exist, ignore error
            }
            
            try {
                $table->dropIndex('tenders_status_created_index');
            } catch (\Exception $e) {
                // Index may not exist, ignore error
            }
            
            try {
                $table->dropIndex('tenders_type_status_index');
            } catch (\Exception $e) {
                // Index may not exist, ignore error
            }
        });
    }
};


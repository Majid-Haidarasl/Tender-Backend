<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * اضافه کردن فیلدهای مورد نیاز برای سیستم هشدار و اعلان
     */
    public function up(): void
    {
        // بررسی وجود جدول قبل از تغییر
        if (!Schema::hasTable('notifications')) {
            return; // اگر جدول وجود ندارد، این migration اجرا نمی‌شود
        }

        // بررسی وجود ستون‌ها قبل از اضافه کردن
        if (!Schema::hasColumn('notifications', 'message_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('message_id', 20)->nullable()->after('id'); // شناسه پیام (W001, E001, I001, ...)
            });
        }
        
        if (!Schema::hasColumn('notifications', 'title')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('title', 500)->nullable()->after('message_id'); // عنوان پیام
            });
        }
        
        if (!Schema::hasColumn('notifications', 'stage')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('stage', 100)->nullable()->after('title'); // مرحله فرآیند
            });
        }
        
        if (!Schema::hasColumn('notifications', 'context')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->text('context')->nullable()->after('details'); // اطلاعات اضافی (JSON)
            });
        }

        // اضافه کردن index ها
        if (Schema::hasColumn('notifications', 'message_id')) {
            try {
                Schema::table('notifications', function (Blueprint $table) {
                    $table->index('message_id');
                });
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
        }
        
        if (Schema::hasColumn('notifications', 'stage')) {
            try {
                Schema::table('notifications', function (Blueprint $table) {
                    $table->index('stage');
                });
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['message_id']);
            $table->dropIndex(['stage']);
            $table->dropColumn(['message_id', 'title', 'stage', 'context']);
        });
    }
};


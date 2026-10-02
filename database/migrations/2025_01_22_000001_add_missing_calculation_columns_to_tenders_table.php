<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * اضافه کردن ستون‌های محاسبه که ممکن است در دیتابیس وجود نداشته باشند
     */
    public function up(): void
    {
        $hasPoCalculatedAt = Schema::hasColumn('tenders', 'po_calculated_at');
        
        // بررسی وجود ستون po_calculated_at قبل از اضافه کردن
        if (!$hasPoCalculatedAt) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->timestamp('po_calculated_at')->nullable()->after('po');
            });
        }
        
        // بررسی وجود ستون evaluation_calculated_at قبل از اضافه کردن
        if (!Schema::hasColumn('tenders', 'evaluation_calculated_at')) {
            Schema::table('tenders', function (Blueprint $table) use ($hasPoCalculatedAt) {
                // اگر po_calculated_at وجود دارد، بعد از آن اضافه می‌شود، وگرنه بعد از po
                if ($hasPoCalculatedAt) {
                    $table->timestamp('evaluation_calculated_at')->nullable()->after('po_calculated_at');
                } else {
                    $table->timestamp('evaluation_calculated_at')->nullable()->after('po');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            if (Schema::hasColumn('tenders', 'po_calculated_at')) {
                $table->dropColumn('po_calculated_at');
            }
            if (Schema::hasColumn('tenders', 'evaluation_calculated_at')) {
                $table->dropColumn('evaluation_calculated_at');
            }
        });
    }
};


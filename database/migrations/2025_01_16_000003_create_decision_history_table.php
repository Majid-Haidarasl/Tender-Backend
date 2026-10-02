<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * جدول تاریخچه تصمیم‌گیری
     */
    public function up(): void
    {
        Schema::create('decision_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tender_id', 36)->index();
            $table->string('stage', 100)->index(); // مرحله فرآیند
            $table->string('decision_path', 50)->index(); // SIMPLE, STATISTICAL, SUSPENDED
            $table->string('decision_type', 50)->index(); // PATH_SELECTION, PRICE_ADJUSTMENT, BID_REJECTION, WINNER_SELECTION
            $table->text('decision_reason')->nullable(); // دلیل تصمیم
            $table->json('input_data')->nullable(); // داده‌های ورودی تصمیم
            $table->json('calculation_data')->nullable(); // داده‌های محاسباتی
            $table->json('result_data')->nullable(); // نتیجه تصمیم
            $table->json('conditions_met')->nullable(); // شروط برقرار شده
            $table->json('conditions_failed')->nullable(); // شروط برقرار نشده
            $table->string('message_id', 20)->nullable(); // شناسه پیام مرتبط
            $table->integer('priority')->default(0);
            $table->boolean('blocks_process')->default(false);
            $table->string('user_id', 36)->nullable();
            $table->timestamps();

            $table->index(['tender_id', 'stage', 'created_at']);
            $table->index(['decision_path', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decision_history');
    }
};


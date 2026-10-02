<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * جدول ثبت تاریخچه (Audit Trail)
     */
    public function up(): void
    {
        Schema::create('audit_trails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tender_id', 36)->index();
            $table->string('user_id', 36)->nullable()->index();
            $table->string('action_type', 50)->index(); // INPUT, CALCULATION, DECISION, ERROR, WARNING
            $table->string('entity_type', 50)->index(); // TENDER, ESTIMATE, INDEX, BIDDER, PO, EVALUATION
            $table->string('entity_id', 36)->nullable()->index();
            $table->string('stage', 100)->index(); // مرحله فرآیند
            $table->string('decision_path', 50)->nullable(); // SIMPLE, STATISTICAL, SUSPENDED
            $table->text('description')->nullable(); // توضیحات
            $table->json('old_values')->nullable(); // مقادیر قبلی
            $table->json('new_values')->nullable(); // مقادیر جدید
            $table->json('calculated_values')->nullable(); // مقادیر محاسبه شده
            $table->json('context')->nullable(); // اطلاعات اضافی
            $table->string('message_id', 20)->nullable(); // شناسه پیام (W001, E001, ...)
            $table->enum('message_type', ['ERROR', 'WARNING', 'INFO'])->nullable();
            $table->integer('priority')->default(0); // اولویت
            $table->boolean('blocks_process')->default(false); // آیا مانع ادامه است
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tender_id', 'created_at']);
            $table->index(['action_type', 'created_at']);
            $table->index(['stage', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_trails');
    }
};


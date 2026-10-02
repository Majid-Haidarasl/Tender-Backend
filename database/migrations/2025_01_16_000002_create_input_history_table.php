<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * جدول تاریخچه ورودی‌ها
     */
    public function up(): void
    {
        Schema::create('input_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tender_id', 36)->index();
            $table->string('user_id', 36)->nullable()->index();
            $table->string('input_type', 50)->index(); // PB, PO, PI, INDEX, BETA, GAMMA
            $table->string('input_name', 100); // نام ورودی
            $table->string('entity_type', 50)->index(); // ESTIMATE, INDEX, BIDDER, etc.
            $table->string('entity_id', 36)->nullable()->index();
            $table->decimal('original_value', 20, 2)->nullable(); // مقدار اصلی
            $table->decimal('modified_value', 20, 2)->nullable(); // مقدار اصلاح شده
            $table->decimal('normalized_value', 20, 8)->nullable(); // مقدار نرمال‌شده
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'MODIFIED'])->default('PENDING');
            $table->text('notes')->nullable();
            $table->json('validation_result')->nullable(); // نتیجه اعتبارسنجی
            $table->string('approved_by', 36)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['tender_id', 'input_type', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('input_history');
    }
};


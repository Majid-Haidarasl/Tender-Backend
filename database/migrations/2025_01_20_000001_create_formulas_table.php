<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('formulas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique(); // نام فرمول (مثل: simple_lower, simple_upper, m62_lower, m62_upper)
            $table->string('category'); // دسته‌بندی (evaluation, po_calculation, etc.)
            $table->text('description')->nullable(); // توضیحات فرمول
            $table->text('formula'); // فرمول (مثل: 0.9 * Po)
            $table->json('parameters')->nullable(); // پارامترهای فرمول
            $table->json('conditions')->nullable(); // شروط اعمال فرمول
            $table->boolean('is_active')->default(true); // فعال/غیرفعال
            $table->boolean('is_default')->default(false); // فرمول پیش‌فرض (غیرقابل ویرایش)
            $table->integer('version')->default(1); // نسخه فرمول
            $table->uuid('created_by')->nullable(); // کاربر ایجادکننده
            $table->uuid('updated_by')->nullable(); // کاربر آخرین ویرایش‌کننده
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['category', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('formulas');
    }
};


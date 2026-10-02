<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: messages (پیام‌های کاربران به پشتیبان)
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id', 36); // فرستنده پیام
            $table->string('subject', 500); // موضوع پیام
            $table->text('message'); // متن پیام
            $table->text('reply')->nullable(); // پاسخ پشتیبان
            $table->timestamp('replied_at')->nullable(); // زمان پاسخ
            $table->string('replied_by', 36)->nullable(); // پشتیبان پاسخ‌دهنده
            $table->enum('status', ['pending', 'replied', 'closed'])->default('pending'); // وضعیت پیام
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
            $table->index('replied_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};


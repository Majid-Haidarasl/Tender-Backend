<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: notifications (اعلانات)
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('message', 1000);
            $table->enum('type', ['info', 'success', 'warning', 'error'])->default('info');
            $table->text('details')->nullable(); // JSON data for additional information
            $table->boolean('read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->string('user_id', 36)->nullable(); // Optional: link to user if needed
            $table->string('tender_id', 36)->nullable(); // Optional: link to tender if notification is related
            $table->timestamps();

            $table->index('read');
            $table->index('type');
            $table->index('created_at');
            $table->index('user_id');
            $table->index('tender_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: indices (شاخص‌ها)
     */
    public function up(): void
    {
        Schema::create('indices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tender_id');
            $table->string('type', 20);
            $table->decimal('value', 15, 8);
            $table->string('date', 20)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('tender_id')
                ->references('id')
                ->on('tenders')
                ->onDelete('cascade');

            $table->index('tender_id');
            $table->index('type');
            $table->unique(['tender_id', 'type'], 'unique_tender_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indices');
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: tenders (مناقصات)
     */
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 100)->unique();
            $table->string('title', 500);
            $table->string('type', 100);
            $table->string('date', 20);
            $table->boolean('is_two_stage')->default(false);
            $table->boolean('normalize_prices')->default(false);
            $table->boolean('is_adjustable')->default(false);
            $table->string('base_period', 100)->nullable();
            $table->string('po_method', 10)->default('1');
            $table->decimal('tgamma', 10, 4)->default(1.0);
            $table->decimal('tbeta', 10, 4)->default(0.5);
            $table->decimal('delta', 10, 4)->default(0);
            $table->decimal('a_max', 10, 2)->nullable();
            $table->decimal('normalization_factor', 10, 4)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->decimal('pb', 20, 2)->default(0);
            $table->decimal('po', 20, 2)->default(0);
            $table->timestamp('po_calculated_at')->nullable();
            $table->timestamp('evaluation_calculated_at')->nullable();
            $table->timestamps();

            $table->index('code');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};


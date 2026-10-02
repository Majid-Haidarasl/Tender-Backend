<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: estimates (برآورد اولیه)
     */
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tender_id');
            $table->string('section', 500);
            $table->decimal('amount', 20, 2);
            $table->string('currency', 10)->default('IRR');
            $table->decimal('exchange_rate', 15, 4)->nullable();
            $table->decimal('amount_in_rials', 20, 2);
            $table->boolean('is_adjustable')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tender_id')
                ->references('id')
                ->on('tenders')
                ->onDelete('cascade');

            $table->index('tender_id');
            $table->index('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};


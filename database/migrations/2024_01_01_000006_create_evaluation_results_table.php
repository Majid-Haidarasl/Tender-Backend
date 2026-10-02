<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: evaluation_results (نتایج ارزیابی)
     */
    public function up(): void
    {
        Schema::create('evaluation_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tender_id');
            $table->uuid('bidder_id');
            $table->decimal('normalized_price', 20, 2)->nullable();
            $table->decimal('technical_score', 5, 2)->nullable();
            $table->decimal('final_score', 20, 2)->nullable();
            $table->integer('rank')->nullable();
            $table->boolean('is_winner')->default(false);
            $table->boolean('is_winner_first')->default(false);
            $table->boolean('is_winner_second')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tender_id')
                ->references('id')
                ->on('tenders')
                ->onDelete('cascade');

            $table->foreign('bidder_id')
                ->references('id')
                ->on('bidders')
                ->onDelete('cascade');

            $table->index('tender_id');
            $table->index('bidder_id');
            $table->index('rank');
            $table->index('final_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_results');
    }
};


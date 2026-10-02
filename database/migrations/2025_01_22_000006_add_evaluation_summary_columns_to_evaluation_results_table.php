<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * اضافه کردن ستون‌های خلاصه ارزیابی به جدول evaluation_results
     */
    public function up(): void
    {
        if (!Schema::hasColumn('evaluation_results', 'action')) {
            Schema::table('evaluation_results', function (Blueprint $table) {
                $table->string('action', 50)->nullable()->after('is_winner_second');
                $table->uuid('winner_bidder_id')->nullable()->after('action');
                $table->decimal('plqp', 30, 2)->nullable()->after('winner_bidder_id');
                $table->decimal('lower_bound', 30, 2)->nullable()->after('plqp');
                $table->decimal('upper_bound', 30, 2)->nullable()->after('lower_bound');
                $table->json('details')->nullable()->after('upper_bound');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('evaluation_results', 'details')) {
            Schema::table('evaluation_results', function (Blueprint $table) {
                $table->dropColumn(['action', 'winner_bidder_id', 'plqp', 'lower_bound', 'upper_bound', 'details']);
            });
        }
    }
};


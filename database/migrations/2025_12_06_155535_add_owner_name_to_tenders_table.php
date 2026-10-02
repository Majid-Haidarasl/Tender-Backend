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
        if (!Schema::hasColumn('tenders', 'owner_name')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->string('owner_name', 500)->nullable()->after('title');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('tenders', 'owner_name')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->dropColumn('owner_name');
            });
        }
    }
};

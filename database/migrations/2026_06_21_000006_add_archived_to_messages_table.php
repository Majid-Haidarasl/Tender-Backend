<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->after('is_read');
            }
            if (!Schema::hasColumn('messages', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('is_archived');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'is_archived')) {
                $table->index('is_archived');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
            if (Schema::hasColumn('messages', 'is_archived')) {
                $table->dropColumn('is_archived');
            }
        });
    }
};

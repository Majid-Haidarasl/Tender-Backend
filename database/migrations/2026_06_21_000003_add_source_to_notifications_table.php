<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'source')) {
                $table->string('source', 30)->default('system')->after('type');
                $table->index('source');
            }
        });

        if (Schema::hasColumn('notifications', 'source')) {
            DB::table('notifications')->whereNull('source')->update(['source' => 'system']);

            DB::table('notifications')
                ->whereNotNull('title')
                ->whereNotNull('user_id')
                ->whereNull('message_id')
                ->update(['source' => 'admin']);
        }
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'source')) {
                $table->dropIndex(['source']);
                $table->dropColumn('source');
            }
        });
    }
};

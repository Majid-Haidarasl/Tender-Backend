<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tenders', 'owner_name') || $this->indexExists('tenders_owner_name_index')) {
            return;
        }

        Schema::table('tenders', function (Blueprint $table) {
            $table->index('owner_name', 'tenders_owner_name_index');
        });
    }

    public function down(): void
    {
        if ($this->indexExists('tenders_owner_name_index')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->dropIndex('tenders_owner_name_index');
            });
        }
    }

    private function indexExists(string $indexName): bool
    {
        $indexes = DB::select('SHOW INDEX FROM `tenders` WHERE Key_name = ?', [$indexName]);

        return count($indexes) > 0;
    }
};

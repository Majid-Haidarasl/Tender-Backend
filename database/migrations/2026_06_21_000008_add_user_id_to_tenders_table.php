<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tenders', 'user_id')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->uuid('user_id')->nullable()->after('id');
            });
        }

        if (!$this->indexExists('tenders', 'tenders_user_id_index')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->index('user_id', 'tenders_user_id_index');
            });
        }

        if (!$this->foreignKeyExists('tenders', 'tenders_user_id_foreign')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->foreign('user_id', 'tenders_user_id_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        $this->dropUniqueIndexOnColumn('tenders', 'code');

        if (!$this->indexExists('tenders', 'tenders_user_id_code_unique')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->unique(['user_id', 'code'], 'tenders_user_id_code_unique');
            });
        }

        if (Schema::hasTable('audit_trail')) {
            $orphanIds = DB::table('tenders')->whereNull('user_id')->pluck('id');

            foreach ($orphanIds as $tenderId) {
                $ownerId = DB::table('audit_trail')
                    ->where('tender_id', $tenderId)
                    ->whereNotNull('user_id')
                    ->orderBy('created_at')
                    ->value('user_id');

                if ($ownerId) {
                    DB::table('tenders')->where('id', $tenderId)->update(['user_id' => $ownerId]);
                }
            }
        }
    }

    public function down(): void
    {
        if ($this->indexExists('tenders', 'tenders_user_id_code_unique')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->dropUnique('tenders_user_id_code_unique');
            });
        }

        if (!$this->indexExists('tenders', 'code')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->unique('code', 'code');
            });
        }

        if ($this->foreignKeyExists('tenders', 'tenders_user_id_foreign')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->dropForeign('tenders_user_id_foreign');
            });
        }

        if ($this->indexExists('tenders', 'tenders_user_id_index')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->dropIndex('tenders_user_id_index');
            });
        }

        if (Schema::hasColumn('tenders', 'user_id')) {
            Schema::table('tenders', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($indexes) > 0;
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $database = DB::getDatabaseName();

        $result = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $constraintName, 'FOREIGN KEY']
        );

        return count($result) > 0;
    }

    private function dropUniqueIndexOnColumn(string $table, string $column): void
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ? AND Non_unique = 0", [$column]);

        foreach ($indexes as $index) {
            if ($index->Key_name === 'PRIMARY') {
                continue;
            }

            if ($index->Key_name === 'tenders_user_id_code_unique') {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index->Key_name}`");
        }
    }
};

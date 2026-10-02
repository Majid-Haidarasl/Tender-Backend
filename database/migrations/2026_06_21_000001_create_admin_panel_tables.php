<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'admin_role')) {
                $table->string('admin_role')->nullable()->after('role');
            }
            if (!Schema::hasColumn('users', 'admin_permissions')) {
                $table->json('admin_permissions')->nullable()->after('admin_role');
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('users', 'failed_login_attempts')) {
                $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('last_login_at');
            }
            if (!Schema::hasColumn('users', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            }
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_published')->default(true);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('cms_page_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cms_page_id');
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->foreign('cms_page_id')->references('id')->on('cms_pages')->onDelete('cascade');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group')->default('general');
            $table->string('description')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('admin_id');
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['admin_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('formula_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('formula_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->uuid('created_by')->nullable();
            $table->string('change_note')->nullable();
            $table->timestamps();
            $table->foreign('formula_id')->references('id')->on('formulas')->onDelete('cascade');
            $table->index(['formula_id', 'version']);
        });

        Schema::table('formulas', function (Blueprint $table) {
            if (!Schema::hasColumn('formulas', 'status')) {
                $table->string('status')->default('published')->after('is_active');
            }
            if (!Schema::hasColumn('formulas', 'draft_data')) {
                $table->json('draft_data')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_versions');
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('cms_page_versions');
        Schema::dropIfExists('cms_pages');

        Schema::table('formulas', function (Blueprint $table) {
            if (Schema::hasColumn('formulas', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('formulas', 'draft_data')) {
                $table->dropColumn('draft_data');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $cols = ['admin_role', 'admin_permissions', 'last_login_at', 'failed_login_attempts', 'locked_until'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

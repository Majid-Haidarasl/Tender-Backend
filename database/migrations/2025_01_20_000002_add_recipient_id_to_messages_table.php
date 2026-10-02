<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add recipient_id to messages table for user-to-user messaging
     */
    public function up(): void
    {
        // بررسی وجود ستون‌ها قبل از اضافه کردن
        if (!Schema::hasColumn('messages', 'recipient_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->string('recipient_id', 36)->nullable()->after('user_id');
            });
        }
        
        if (!Schema::hasColumn('messages', 'conversation_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->string('conversation_id', 36)->nullable()->after('recipient_id');
            });
        }
        
        if (!Schema::hasColumn('messages', 'is_read')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->boolean('is_read')->default(false)->after('status');
            });
        }
        
        if (!Schema::hasColumn('messages', 'read_at')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->timestamp('read_at')->nullable()->after('is_read');
            });
        }
        
        // اضافه کردن index ها
        if (Schema::hasColumn('messages', 'recipient_id')) {
            try {
                Schema::table('messages', function (Blueprint $table) {
                    $table->index('recipient_id');
                });
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
        }
        
        if (Schema::hasColumn('messages', 'conversation_id')) {
            try {
                Schema::table('messages', function (Blueprint $table) {
                    $table->index('conversation_id');
                });
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
        }
        
        if (Schema::hasColumn('messages', 'is_read')) {
            try {
                Schema::table('messages', function (Blueprint $table) {
                    $table->index('is_read');
                });
            } catch (\Exception $e) {
                // Index ممکن است از قبل وجود داشته باشد
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['recipient_id']);
            $table->dropIndex(['conversation_id']);
            $table->dropIndex(['is_read']);
            $table->dropColumn(['recipient_id', 'conversation_id', 'is_read', 'read_at']);
        });
    }
};


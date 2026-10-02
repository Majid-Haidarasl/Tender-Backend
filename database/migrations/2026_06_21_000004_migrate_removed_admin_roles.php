<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereIn('admin_role', ['content_admin', 'formula_admin'])
            ->update(['admin_role' => 'support_admin']);
    }

    public function down(): void
    {
        // Roles removed; no safe automatic rollback.
    }
};

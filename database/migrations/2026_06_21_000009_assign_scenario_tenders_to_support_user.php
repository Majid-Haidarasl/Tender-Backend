<?php

use App\Services\SupportContactService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * مناقصات نمونه/سناریوی سیستم به حساب «پشتیبان سایت» (majidsupp) منتسب می‌شوند.
     */
    public function up(): void
    {
        $supportUserId = DB::table('users')
            ->where('username', SupportContactService::SUPER_ADMIN_USERNAME)
            ->value('id');

        if (!$supportUserId) {
            return;
        }

        DB::table('tenders')
            ->where(function ($query) {
                $query->where('code', 'like', 'SCN-%')
                    ->orWhere('code', 'like', 'TND-%')
                    ->orWhere('code', 'like', 'MOP-%');
            })
            ->update(['user_id' => $supportUserId]);
    }

    public function down(): void
    {
        DB::table('tenders')
            ->where(function ($query) {
                $query->where('code', 'like', 'SCN-%')
                    ->orWhere('code', 'like', 'TND-%')
                    ->orWhere('code', 'like', 'MOP-%');
            })
            ->update(['user_id' => null]);
    }
};

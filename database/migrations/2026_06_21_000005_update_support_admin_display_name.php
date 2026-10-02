<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('username', 'majidsupp')
            ->update(['full_name' => 'پشتیبان سایت']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('username', 'majidsupp')
            ->update(['full_name' => 'مدیر ارشد سیستم']);
    }
};

<?php

use App\Services\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SystemSettingsService::set(
            'app_version',
            config('release.version', '3.0.0'),
            'system'
        );
    }

    public function down(): void
    {
        SystemSettingsService::set('app_version', '2.0.0', 'system');
    }
};

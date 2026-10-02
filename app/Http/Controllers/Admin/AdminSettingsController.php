<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditService;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->successResponse(SystemSettingsService::allGrouped());
    }

    public function update(Request $request): JsonResponse
    {
        $settings = $request->input('settings', []);
        $admin = $request->user();
        $updated = [];

        foreach ($settings as $key => $value) {
            $group = match (true) {
                str_starts_with($key, 'feature_') => 'features',
                str_starts_with($key, 'default_') => 'defaults',
                in_array($key, ['system_announcement', 'app_version']) => 'system',
                default => 'general',
            };

            SystemSettingsService::set($key, $value, $group, $admin->id);
            $updated[$key] = $value;
        }

        AdminAuditService::log(
            $admin->id, 'settings.update', 'system_settings', null,
            null, $updated, 'به‌روزرسانی تنظیمات سیستم', $request
        );

        return $this->successResponse(SystemSettingsService::allGrouped(), 'تنظیمات با موفقیت ذخیره شد');
    }
}

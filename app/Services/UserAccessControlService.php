<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * سرویس کنترل دسترسی کاربر
 * 
 * این سرویس دسترسی کاربران به داده‌ها و تاریخچه را کنترل می‌کند
 */
class UserAccessControlService
{
    // سطوح دسترسی
    const ACCESS_LEVEL_ADMIN = 'admin';
    const ACCESS_LEVEL_COMMITTEE = 'committee';
    const ACCESS_LEVEL_VIEWER = 'viewer';
    const ACCESS_LEVEL_BIDDER = 'bidder';

    /**
     * بررسی دسترسی کاربر به مناقصه
     */
    public function hasAccessToTender(string $userId, string $tenderId, string $requiredLevel = self::ACCESS_LEVEL_VIEWER): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }

        $tender = Tender::find($tenderId);
        if (!$tender) {
            return false;
        }

        if ($tender->user_id && $tender->user_id !== $userId) {
            return false;
        }

        if ($tender->user_id === $userId) {
            return true;
        }

        // Admin همیشه دسترسی دارد (پنل مدیریت)
        if ($this->isAdmin($user)) {
            return true;
        }

        // بررسی سطح دسترسی
        $userLevel = $this->getUserAccessLevel($user);
        $levelHierarchy = [
            self::ACCESS_LEVEL_ADMIN => 4,
            self::ACCESS_LEVEL_COMMITTEE => 3,
            self::ACCESS_LEVEL_VIEWER => 2,
            self::ACCESS_LEVEL_BIDDER => 1,
        ];

        $userLevelValue = $levelHierarchy[$userLevel] ?? 0;
        $requiredLevelValue = $levelHierarchy[$requiredLevel] ?? 0;

        return $userLevelValue >= $requiredLevelValue;
    }

    /**
     * بررسی دسترسی به تاریخچه
     */
    public function hasAccessToHistory(string $userId, string $tenderId): bool
    {
        return $this->hasAccessToTender($userId, $tenderId, self::ACCESS_LEVEL_VIEWER);
    }

    /**
     * بررسی دسترسی به Audit Trail
     */
    public function hasAccessToAuditTrail(string $userId, string $tenderId): bool
    {
        return $this->hasAccessToTender($userId, $tenderId, self::ACCESS_LEVEL_COMMITTEE);
    }

    /**
     * فیلتر تاریخچه بر اساس دسترسی کاربر
     */
    public function filterHistoryByAccess(string $userId, array $history): array
    {
        $user = User::find($userId);
        if (!$user) {
            return [];
        }

        // Admin همه چیز را می‌بیند
        if ($this->isAdmin($user)) {
            return $history;
        }

        $userLevel = $this->getUserAccessLevel($user);

        // فیلتر بر اساس سطح دسترسی
        $filtered = $history;
        
        if ($userLevel === self::ACCESS_LEVEL_BIDDER) {
            // پیشنهاددهنده فقط داده‌های خودش را می‌بیند
            $filtered = array_filter($history, function ($entry) use ($userId) {
                return ($entry['user_id'] ?? null) === $userId;
            });
        } elseif ($userLevel === self::ACCESS_LEVEL_VIEWER) {
            // Viewer فقط اطلاعات عمومی را می‌بیند
            $filtered = array_filter($history, function ($entry) {
                $actionType = $entry['action_type'] ?? '';
                return in_array($actionType, [
                    \App\Models\AuditTrail::ACTION_INFO,
                ]);
            });
        }

        return array_values($filtered);
    }

    /**
     * بررسی اینکه آیا کاربر Admin است
     */
    private function isAdmin(User $user): bool
    {
        // بررسی role کاربر
        if ($user->role === 'admin') {
            return true;
        }
        
        // بررسی email (برای backward compatibility)
        $adminEmails = config('app.admin_emails', []);
        if (is_array($adminEmails) && in_array($user->email, $adminEmails)) {
            return true;
        }
        
        return false;
    }

    /**
     * دریافت سطح دسترسی کاربر
     */
    private function getUserAccessLevel(User $user): string
    {
        // Admin
        if ($this->isAdmin($user)) {
            return self::ACCESS_LEVEL_ADMIN;
        }

        // Committee member
        if ($user->tender_committee_role && in_array($user->tender_committee_role, ['president', 'member', 'secretary'])) {
            return self::ACCESS_LEVEL_COMMITTEE;
        }

        // Bidder (اگر role مشخص شده باشد)
        if ($user->role === 'bidder') {
            return self::ACCESS_LEVEL_BIDDER;
        }

        // Default: Viewer
        return self::ACCESS_LEVEL_VIEWER;
    }

    /**
     * بررسی دسترسی به داده‌های حساس
     */
    public function hasAccessToSensitiveData(string $userId, string $tenderId): bool
    {
        return $this->hasAccessToTender($userId, $tenderId, self::ACCESS_LEVEL_COMMITTEE);
    }
}


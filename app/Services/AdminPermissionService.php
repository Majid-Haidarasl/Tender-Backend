<?php

namespace App\Services;

use App\Models\User;

class AdminPermissionService
{
    public const PERMISSIONS = [
        'dashboard',
        'users',
        'formulas',
        'messages',
        'cms',
        'settings',
        'audit',
        'analytics',
        'tenders',
        'system',
    ];

    public const ROLE_PERMISSIONS = [
        'super_admin' => self::PERMISSIONS,
        'support_admin' => ['dashboard', 'users', 'messages', 'analytics', 'tenders', 'cms'],
    ];

    public static function hasPermission(?User $user, string $permission): bool
    {
        if (!$user || $user->role !== 'admin') {
            return false;
        }

        $permissions = self::getPermissions($user);
        return in_array($permission, $permissions, true);
    }

    public static function getPermissions(User $user): array
    {
        if ($user->role !== 'admin') {
            return [];
        }

        if ($user->admin_permissions && is_array($user->admin_permissions)) {
            return $user->admin_permissions;
        }

        if ($user->admin_role && isset(self::ROLE_PERMISSIONS[$user->admin_role])) {
            return self::ROLE_PERMISSIONS[$user->admin_role];
        }

        return self::PERMISSIONS;
    }

    public static function isSuperAdmin(User $user): bool
    {
        return $user->role === 'admin' && (
            $user->admin_role === 'super_admin' ||
            $user->admin_role === null ||
            empty($user->admin_permissions)
        );
    }
}

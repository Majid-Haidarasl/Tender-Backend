<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SupportContactService
{
    public const SUPER_ADMIN_USERNAME = 'majidsupp';

    public static function superAdmin(): ?User
    {
        return User::where('username', self::SUPER_ADMIN_USERNAME)
            ->where('is_active', true)
            ->first();
    }

    public static function superAdminId(): ?string
    {
        return self::superAdmin()?->id;
    }

    public static function applySupportInboxFilter(Builder $query): Builder
    {
        $superAdminId = self::superAdminId();

        return $query->where(function (Builder $q) use ($superAdminId) {
            $q->whereNull('recipient_id');
            if ($superAdminId) {
                $q->orWhere('recipient_id', $superAdminId);
            }
        });
    }

    public static function isSupportMessage(Message $message): bool
    {
        $superAdminId = self::superAdminId();

        if ($message->recipient_id === null) {
            return true;
        }

        return $superAdminId && $message->recipient_id === $superAdminId;
    }

    /**
     * Resolve recipient for support messages (empty recipient → super admin).
     */
    public static function resolveRecipientId(?string $recipientId, string $senderId): ?string
    {
        if ($recipientId) {
            return $recipientId;
        }

        $superAdminId = self::superAdminId();
        if ($superAdminId && $superAdminId !== $senderId) {
            return $superAdminId;
        }

        return null;
    }
}

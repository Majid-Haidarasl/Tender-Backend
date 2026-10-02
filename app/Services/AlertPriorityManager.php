<?php

namespace App\Services;

use App\Services\AlertNotificationService;

/**
 * مدیریت اولویت‌های پیام‌ها
 * 
 * این کلاس اولویت‌ها را مدیریت می‌کند و تصمیم می‌گیرد
 * کدام خطا مانع ادامه فرآیند است
 */
class AlertPriorityManager
{
    /**
     * بررسی اینکه آیا پیام مانع ادامه فرآیند است
     */
    public static function blocksProcess(string $messageId): bool
    {
        $message = (new AlertNotificationService())->getMessage($messageId);
        if (!$message) {
            return false;
        }

        // خطاها همیشه مانع ادامه هستند
        if ($message['type'] === AlertNotificationService::TYPE_ERROR) {
            return true;
        }

        // بررسی فیلد blocks_process
        if (isset($message['blocks_process']) && $message['blocks_process']) {
            return true;
        }

        // بررسی اولویت
        $priority = $message['priority'] ?? AlertNotificationService::PRIORITY_LOW;
        if ($priority >= AlertNotificationService::PRIORITY_HIGH) {
            return true;
        }

        return false;
    }

    /**
     * دریافت اولویت پیام
     */
    public static function getPriority(string $messageId): int
    {
        $message = (new AlertNotificationService())->getMessage($messageId);
        if (!$message) {
            return AlertNotificationService::PRIORITY_LOW;
        }

        return $message['priority'] ?? AlertNotificationService::PRIORITY_LOW;
    }

    /**
     * مرتب‌سازی پیام‌ها بر اساس اولویت
     */
    public static function sortByPriority(array $messages): array
    {
        usort($messages, function ($a, $b) {
            $priorityA = self::getPriority($a['message_id'] ?? '');
            $priorityB = self::getPriority($b['message_id'] ?? '');
            return $priorityB <=> $priorityA; // نزولی
        });

        return $messages;
    }

    /**
     * فیلتر کردن پیام‌های مانع
     */
    public static function filterBlockingMessages(array $messages): array
    {
        return array_filter($messages, function ($message) {
            return self::blocksProcess($message['message_id'] ?? '');
        });
    }
}


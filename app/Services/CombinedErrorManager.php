<?php

namespace App\Services;

use App\Services\AlertNotificationService;
use App\Services\AlertPriorityManager;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Log;

/**
 * مدیریت خطاهای ترکیبی
 * 
 * این سرویس خطاهای ترکیبی را مدیریت می‌کند و اولویت‌بندی می‌کند
 */
class CombinedErrorManager
{
    private $alertService;
    private $auditTrailService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
        $this->auditTrailService = new AuditTrailService();
    }

    /**
     * مدیریت خطاهای ترکیبی با اولویت‌بندی
     * 
     * @param string $tenderId
     * @param array $errors آرایه خطاها
     * @return array ['processed' => [], 'blocking' => [], 'warnings' => [], 'info' => []]
     */
    public function manageCombinedErrors(string $tenderId, array $errors): array
    {
        // مرتب‌سازی بر اساس اولویت
        $sortedErrors = AlertPriorityManager::sortByPriority($errors);

        // تفکیک بر اساس نوع
        $blocking = [];
        $warnings = [];
        $info = [];
        $processed = [];

        foreach ($sortedErrors as $error) {
            $messageId = $error['message_id'] ?? '';
            $messageType = $error['type'] ?? 'INFO';
            $priority = AlertPriorityManager::getPriority($messageId);
            $blocks = AlertPriorityManager::blocksProcess($messageId);

            // ثبت در Audit Trail
            $this->auditTrailService->logMessage(
                $tenderId,
                $messageId,
                $messageType,
                $error['stage'] ?? 'خطای ترکیبی',
                null,
                $priority,
                $blocks,
                $error
            );

            if ($blocks) {
                $blocking[] = $error;
            } elseif ($messageType === 'WARNING') {
                $warnings[] = $error;
            } else {
                $info[] = $error;
            }

            $processed[] = $error;
        }

        // اگر خطای مانع وجود دارد، فرآیند متوقف می‌شود
        if (!empty($blocking)) {
            Log::error('Combined errors blocking process', [
                'tender_id' => $tenderId,
                'blocking_errors' => $blocking,
            ]);
        }

        return [
            'processed' => $processed,
            'blocking' => $blocking,
            'warnings' => $warnings,
            'info' => $info,
            'should_stop' => !empty($blocking),
        ];
    }

    /**
     * بررسی و مدیریت خطاهای ترکیبی یک مناقصه
     */
    public function checkAndManageCombinedErrors(string $tenderId): array
    {
        $tender = \App\Models\Tender::find($tenderId);
        if (!$tender) {
            return [
                'processed' => [],
                'blocking' => [],
                'warnings' => [],
                'info' => [],
                'should_stop' => false,
            ];
        }

        $allErrors = [];

        // جمع‌آوری تمام خطاها
        $combinedErrors = $this->alertService->checkCombinedErrors($tenderId, [
            'pi_complete' => !empty(\App\Models\Bidder::getByTenderId($tenderId)),
            'indices_incomplete' => empty(\App\Models\Index::getByTenderId($tenderId)),
            'po' => floatval($tender->po ?? 0),
        ]);

        $allErrors = array_merge($allErrors, $combinedErrors);

        // مدیریت خطاهای ترکیبی
        return $this->manageCombinedErrors($tenderId, $allErrors);
    }
}


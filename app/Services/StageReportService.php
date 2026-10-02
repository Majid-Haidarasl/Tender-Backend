<?php

namespace App\Services;

use App\Models\Tender;
use App\Services\AlertNotificationService;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * سرویس گزارش و بازخورد به کاربر
 * 
 * بعد از هر مرحله، گزارش وضعیت، نتایج محاسبات و پیام‌ها را نمایش می‌دهد
 */
class StageReportService
{
    private $alertService;
    private $auditTrailService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
        $this->auditTrailService = new AuditTrailService();
    }

    /**
     * تولید گزارش مرحله
     * 
     * @param string $tenderId
     * @param string $stage مرحله فعلی
     * @param array $stageData داده‌های مرحله
     * @return array
     */
    public function generateStageReport(string $tenderId, string $stage, array $stageData = []): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'success' => false,
                'message' => 'مناقصه یافت نشد',
            ];
        }

        // دریافت پیام‌های این مرحله
        $notificationsQuery = \App\Models\Notification::where('tender_id', $tenderId);
        
        // بررسی وجود ستون stage
        if (DB::getSchemaBuilder()->hasColumn('notifications', 'stage')) {
            $notificationsQuery->where('stage', $stage);
        }
        
        $notifications = $notificationsQuery->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
        
        $stageMessages = array_map(function ($notification) {
            $messageId = $notification['message_id'] ?? '';
            $messageConfig = $this->alertService->getMessage($messageId);
            return [
                'message_id' => $messageId,
                'type' => $messageConfig['type'] ?? 'INFO',
                'stage' => $notification['stage'] ?? '',
                'message' => $notification['message'] ?? $messageConfig['message'] ?? '',
                'context' => $notification['context'] ?? [],
                'created_at' => $notification['created_at'] ?? null,
            ];
        }, $notifications);

        // دریافت تاریخچه این مرحله
        $history = $this->auditTrailService->getTenderHistory($tenderId);
        $stageHistory = array_filter($history['audit_trails'], function ($entry) use ($stage) {
            return ($entry['stage'] ?? '') === $stage;
        });

        // تولید گزارش
        $report = [
            'tender_id' => $tenderId,
            'tender_code' => $tender->code,
            'stage' => $stage,
            'timestamp' => now()->toDateTimeString(),
            'status' => $this->determineStageStatus($stageMessages, $stageData),
            'summary' => [
                'errors_count' => count(array_filter($stageMessages, fn($m) => ($m['type'] ?? '') === 'ERROR')),
                'warnings_count' => count(array_filter($stageMessages, fn($m) => ($m['type'] ?? '') === 'WARNING')),
                'info_count' => count(array_filter($stageMessages, fn($m) => ($m['type'] ?? '') === 'INFO')),
            ],
            'messages' => array_values($stageMessages),
            'calculations' => $this->extractCalculations($stageData),
            'decisions' => $this->extractDecisions($stageHistory),
            'next_stage' => $this->determineNextStage($stage, $tender),
            'can_proceed' => $this->canProceedToNextStage($stageMessages),
        ];

        // ثبت گزارش در Audit Trail
        $this->auditTrailService->logMessage(
            $tenderId,
            'STAGE_REPORT',
            'INFO',
            $stage,
            null,
            25,
            false,
            ['report' => $report]
        );

        return $report;
    }

    /**
     * تولید گزارش جامع مناقصه
     */
    public function generateComprehensiveReport(string $tenderId): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'success' => false,
                'message' => 'مناقصه یافت نشد',
            ];
        }

        $stages = [
            AlertNotificationService::STAGE_PB_INPUT,
            AlertNotificationService::STAGE_INDEX_INPUT,
            AlertNotificationService::STAGE_PO_CALCULATION,
            AlertNotificationService::STAGE_PI_INPUT,
            AlertNotificationService::STAGE_DECISION_PATH,
            AlertNotificationService::STAGE_STATISTICAL_ANALYSIS,
            AlertNotificationService::STAGE_NORMALIZATION,
            AlertNotificationService::STAGE_WINNER_SELECTION,
        ];

        if ($tender->is_two_stage) {
            $stages[] = AlertNotificationService::STAGE_TECHNICAL_COMMERCIAL;
        }

        $stageReports = [];
        foreach ($stages as $stage) {
            $stageReport = $this->generateStageReport($tenderId, $stage);
            $stageReports[$stage] = $stageReport;
        }

        // خلاصه کلی
        $summary = $this->auditTrailService->getHistorySummary($tenderId);

        return [
            'tender_id' => $tenderId,
            'tender_code' => $tender->code,
            'timestamp' => now()->toDateTimeString(),
            'overall_status' => $this->determineOverallStatus($stageReports),
            'summary' => $summary,
            'stages' => $stageReports,
            'recommendations' => $this->generateRecommendations($stageReports),
        ];
    }

    /**
     * تعیین وضعیت مرحله
     */
    private function determineStageStatus(array $messages, array $stageData): string
    {
        $hasErrors = !empty(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'ERROR'));
        $hasWarnings = !empty(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'WARNING'));

        if ($hasErrors) {
            return 'ERROR';
        } elseif ($hasWarnings) {
            return 'WARNING';
        } else {
            return 'SUCCESS';
        }
    }

    /**
     * استخراج محاسبات از داده‌های مرحله
     */
    private function extractCalculations(array $stageData): array
    {
        $calculations = [];

        if (isset($stageData['po'])) {
            $calculations['po'] = [
                'value' => $stageData['po'],
                'formula' => $stageData['formula'] ?? null,
                'method' => $stageData['method'] ?? null,
            ];
        }

        if (isset($stageData['beta'])) {
            $calculations['beta'] = $stageData['beta'];
        }

        if (isset($stageData['gamma'])) {
            $calculations['gamma'] = $stageData['gamma'];
        }

        if (isset($stageData['mean'])) {
            $calculations['mean'] = $stageData['mean'];
        }

        if (isset($stageData['std_dev'])) {
            $calculations['std_dev'] = $stageData['std_dev'];
        }

        return $calculations;
    }

    /**
     * استخراج تصمیم‌ها از تاریخچه
     */
    private function extractDecisions(array $history): array
    {
        $decisions = [];

        foreach ($history as $entry) {
            if (($entry['action_type'] ?? '') === 'DECISION') {
                $decisions[] = [
                    'decision_path' => $entry['decision_path'] ?? null,
                    'reason' => $entry['description'] ?? null,
                    'timestamp' => $entry['created_at'] ?? null,
                ];
            }
        }

        return $decisions;
    }

    /**
     * تعیین مرحله بعدی
     */
    private function determineNextStage(string $currentStage, Tender $tender): ?string
    {
        $stageFlow = [
            AlertNotificationService::STAGE_PB_INPUT => AlertNotificationService::STAGE_INDEX_INPUT,
            AlertNotificationService::STAGE_INDEX_INPUT => AlertNotificationService::STAGE_PO_CALCULATION,
            AlertNotificationService::STAGE_PO_CALCULATION => AlertNotificationService::STAGE_PI_INPUT,
            AlertNotificationService::STAGE_PI_INPUT => AlertNotificationService::STAGE_DECISION_PATH,
            AlertNotificationService::STAGE_DECISION_PATH => AlertNotificationService::STAGE_STATISTICAL_ANALYSIS,
            AlertNotificationService::STAGE_STATISTICAL_ANALYSIS => AlertNotificationService::STAGE_NORMALIZATION,
            AlertNotificationService::STAGE_NORMALIZATION => AlertNotificationService::STAGE_WINNER_SELECTION,
        ];

        if ($tender->is_two_stage) {
            $stageFlow[AlertNotificationService::STAGE_PO_CALCULATION] = AlertNotificationService::STAGE_TECHNICAL_COMMERCIAL;
            $stageFlow[AlertNotificationService::STAGE_TECHNICAL_COMMERCIAL] = AlertNotificationService::STAGE_PI_INPUT;
        }

        return $stageFlow[$currentStage] ?? null;
    }

    /**
     * بررسی امکان ادامه به مرحله بعد
     */
    private function canProceedToNextStage(array $messages): bool
    {
        $blockingErrors = array_filter($messages, function ($msg) {
            $messageId = $msg['message_id'] ?? '';
            return \App\Services\AlertPriorityManager::blocksProcess($messageId);
        });

        return empty($blockingErrors);
    }

    /**
     * تعیین وضعیت کلی
     */
    private function determineOverallStatus(array $stageReports): string
    {
        $hasError = false;
        $hasWarning = false;

        foreach ($stageReports as $report) {
            $status = $report['status'] ?? 'SUCCESS';
            if ($status === 'ERROR') {
                $hasError = true;
            } elseif ($status === 'WARNING') {
                $hasWarning = true;
            }
        }

        if ($hasError) {
            return 'ERROR';
        } elseif ($hasWarning) {
            return 'WARNING';
        } else {
            return 'SUCCESS';
        }
    }

    /**
     * تولید توصیه‌ها
     */
    private function generateRecommendations(array $stageReports): array
    {
        $recommendations = [];

        foreach ($stageReports as $stage => $report) {
            if (($report['status'] ?? '') === 'ERROR') {
                $recommendations[] = [
                    'stage' => $stage,
                    'type' => 'ERROR',
                    'message' => 'لطفاً خطاهای این مرحله را برطرف کنید',
                    'actions' => $this->getRecommendedActions($stage, $report),
                ];
            } elseif (($report['status'] ?? '') === 'WARNING') {
                $recommendations[] = [
                    'stage' => $stage,
                    'type' => 'WARNING',
                    'message' => 'لطفاً هشدارهای این مرحله را بررسی کنید',
                    'actions' => $this->getRecommendedActions($stage, $report),
                ];
            }
        }

        return $recommendations;
    }

    /**
     * دریافت اقدامات توصیه شده
     */
    private function getRecommendedActions(string $stage, array $report): array
    {
        $actions = [];

        $errors = array_filter($report['messages'] ?? [], fn($m) => ($m['type'] ?? '') === 'ERROR');
        foreach ($errors as $error) {
            $messageId = $error['message_id'] ?? '';
            $message = $this->alertService->getMessage($messageId);
            if ($message) {
                $actions[] = $message['message'];
            }
        }

        return $actions;
    }
}


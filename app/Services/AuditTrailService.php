<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\InputHistory;
use App\Models\DecisionHistory;
use App\Models\Tender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * سرویس ثبت تاریخچه (Audit Trail)
 * 
 * این سرویس تمام ورودی‌ها، تصمیم‌گیری‌ها و محاسبات را ثبت می‌کند
 */
class AuditTrailService
{
    /**
     * ثبت ورودی
     */
    public function logInput(
        string $tenderId,
        string $inputType,
        string $inputName,
        string $entityType,
        ?string $entityId,
        ?float $originalValue,
        ?float $modifiedValue = null,
        ?float $normalizedValue = null,
        ?array $validationResult = null,
        ?string $stage = null
    ): void {
        try {
            // ثبت در input_history (اگر جدول وجود داشته باشد)
            if (DB::getSchemaBuilder()->hasTable('input_history')) {
                InputHistory::create([
                    'tender_id' => $tenderId,
                    'user_id' => Auth::id(),
                    'input_type' => $inputType,
                    'input_name' => $inputName,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'original_value' => $originalValue,
                    'modified_value' => $modifiedValue,
                    'normalized_value' => $normalizedValue,
                    'status' => $validationResult ? ($validationResult['valid'] ? InputHistory::STATUS_APPROVED : InputHistory::STATUS_REJECTED) : InputHistory::STATUS_PENDING,
                    'validation_result' => $validationResult,
                ]);
            }

            // ثبت در audit_trails (اگر جدول وجود داشته باشد)
            if (DB::getSchemaBuilder()->hasTable('audit_trails')) {
                AuditTrail::create([
                    'tender_id' => $tenderId,
                    'user_id' => Auth::id(),
                    'action_type' => AuditTrail::ACTION_INPUT,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'stage' => $stage ?? 'ورودی داده',
                    'description' => "ورودی {$inputName}: {$originalValue}",
                    'old_values' => null,
                    'new_values' => [
                        'original' => $originalValue,
                        'modified' => $modifiedValue,
                        'normalized' => $normalizedValue,
                    ],
                    'context' => [
                        'input_type' => $inputType,
                        'validation_result' => $validationResult,
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to log input', [
                'tender_id' => $tenderId,
                'input_type' => $inputType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ثبت محاسبه
     */
    public function logCalculation(
        string $tenderId,
        string $calculationType,
        array $inputData,
        array $resultData,
        ?string $stage = null,
        ?string $formula = null
    ): void {
        try {
            // ثبت در audit_trails (اگر جدول وجود داشته باشد)
            if (!DB::getSchemaBuilder()->hasTable('audit_trails')) {
                return;
            }
            
            AuditTrail::create([
                'tender_id' => $tenderId,
                'user_id' => Auth::id(),
                'action_type' => AuditTrail::ACTION_CALCULATION,
                'entity_type' => 'CALCULATION',
                'stage' => $stage ?? 'محاسبه',
                'description' => "محاسبه {$calculationType}",
                'old_values' => null,
                'new_values' => null,
                'calculated_values' => $resultData,
                'context' => [
                    'calculation_type' => $calculationType,
                    'input_data' => $inputData,
                    'formula' => $formula,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log calculation', [
                'tender_id' => $tenderId,
                'calculation_type' => $calculationType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ثبت تصمیم
     */
    public function logDecision(
        string $tenderId,
        string $decisionType,
        string $decisionPath,
        string $stage,
        string $decisionReason,
        array $inputData,
        array $calculationData,
        array $resultData,
        array $conditionsMet,
        array $conditionsFailed = [],
        ?string $messageId = null,
        ?int $priority = null,
        ?bool $blocksProcess = false
    ): void {
        try {
            // ثبت در decision_history (اگر جدول وجود داشته باشد)
            if (DB::getSchemaBuilder()->hasTable('decision_history')) {
                DecisionHistory::create([
                    'tender_id' => $tenderId,
                    'stage' => $stage,
                    'decision_path' => $decisionPath,
                    'decision_type' => $decisionType,
                    'decision_reason' => $decisionReason,
                    'input_data' => $inputData,
                    'calculation_data' => $calculationData,
                    'result_data' => $resultData,
                    'conditions_met' => $conditionsMet,
                    'conditions_failed' => $conditionsFailed,
                    'message_id' => $messageId,
                    'priority' => $priority ?? 0,
                    'blocks_process' => $blocksProcess,
                    'user_id' => Auth::id(),
                ]);
            }

            // ثبت در audit_trails (اگر جدول وجود داشته باشد)
            if (DB::getSchemaBuilder()->hasTable('audit_trails')) {
                AuditTrail::create([
                    'tender_id' => $tenderId,
                    'user_id' => Auth::id(),
                    'action_type' => AuditTrail::ACTION_DECISION,
                    'entity_type' => 'DECISION',
                    'stage' => $stage,
                    'decision_path' => $decisionPath,
                    'description' => $decisionReason,
                    'old_values' => null,
                    'new_values' => $resultData,
                    'calculated_values' => $calculationData,
                    'context' => [
                        'decision_type' => $decisionType,
                        'input_data' => $inputData,
                        'conditions_met' => $conditionsMet,
                        'conditions_failed' => $conditionsFailed,
                    ],
                    'message_id' => $messageId,
                    'message_type' => $messageId ? $this->getMessageType($messageId) : null,
                    'priority' => $priority ?? 0,
                    'blocks_process' => $blocksProcess,
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to log decision', [
                'tender_id' => $tenderId,
                'decision_type' => $decisionType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ثبت پیام (خطا، هشدار، اطلاع)
     */
    public function logMessage(
        string $tenderId,
        string $messageId,
        string $messageType,
        string $stage,
        ?string $decisionPath = null,
        ?int $priority = null,
        ?bool $blocksProcess = false,
        ?array $context = null
    ): void {
        try {
            // ثبت در audit_trails (اگر جدول وجود داشته باشد)
            if (!DB::getSchemaBuilder()->hasTable('audit_trails')) {
                return;
            }
            
            AuditTrail::create([
                'tender_id' => $tenderId,
                'user_id' => Auth::id(),
                'action_type' => match ($messageType) {
                    'ERROR' => AuditTrail::ACTION_ERROR,
                    'WARNING' => AuditTrail::ACTION_WARNING,
                    'INFO' => AuditTrail::ACTION_INFO,
                    default => AuditTrail::ACTION_INFO,
                },
                'entity_type' => 'MESSAGE',
                'stage' => $stage,
                'decision_path' => $decisionPath,
                'description' => "پیام {$messageId}: {$messageType}",
                'message_id' => $messageId,
                'message_type' => $messageType,
                'priority' => $priority ?? 0,
                'blocks_process' => $blocksProcess,
                'context' => $context,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log message', [
                'tender_id' => $tenderId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * دریافت تاریخچه کامل یک مناقصه
     */
    public function getTenderHistory(string $tenderId, ?string $userId = null): array
    {
        // بررسی دسترسی کاربر
        if ($userId) {
            $accessControl = new UserAccessControlService();
            if (!$accessControl->hasAccessToHistory($userId, $tenderId)) {
                throw new \Exception('دسترسی به تاریخچه این مناقصه مجاز نیست');
            }
        }

        $auditTrails = AuditTrail::getByTenderId($tenderId);
        $inputHistory = InputHistory::getByTenderId($tenderId);
        $decisionHistory = DecisionHistory::getByTenderId($tenderId);

        // فیلتر بر اساس دسترسی کاربر
        if ($userId) {
            $accessControl = new UserAccessControlService();
            $auditTrails = $accessControl->filterHistoryByAccess($userId, $auditTrails);
            $inputHistory = $accessControl->filterHistoryByAccess($userId, $inputHistory);
            $decisionHistory = $accessControl->filterHistoryByAccess($userId, $decisionHistory);
        }

        return [
            'tender_id' => $tenderId,
            'audit_trails' => $auditTrails,
            'input_history' => $inputHistory,
            'decision_history' => $decisionHistory,
            'total_entries' => count($auditTrails) + count($inputHistory) + count($decisionHistory),
        ];
    }

    /**
     * بازپخش سناریو (Replay)
     */
    public function replayScenario(string $tenderId, ?string $stage = null): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            throw new \Exception('مناقصه یافت نشد');
        }

        $history = $this->getTenderHistory($tenderId);
        
        // بازسازی ورودی‌ها
        $reconstructedInputs = [];
        foreach ($history['input_history'] as $input) {
            $reconstructedInputs[$input['input_type']] = [
                'original' => $input['original_value'],
                'modified' => $input['modified_value'],
                'normalized' => $input['normalized_value'],
                'status' => $input['status'],
            ];
        }

        // بازسازی تصمیم‌ها
        $reconstructedDecisions = [];
        foreach ($history['decision_history'] as $decision) {
            if (!$stage || $decision['stage'] === $stage) {
                $reconstructedDecisions[] = [
                    'stage' => $decision['stage'],
                    'decision_path' => $decision['decision_path'],
                    'decision_type' => $decision['decision_type'],
                    'reason' => $decision['decision_reason'],
                    'conditions_met' => $decision['conditions_met'],
                    'conditions_failed' => $decision['conditions_failed'],
                    'result' => $decision['result_data'],
                ];
            }
        }

        return [
            'tender_id' => $tenderId,
            'reconstructed_inputs' => $reconstructedInputs,
            'reconstructed_decisions' => $reconstructedDecisions,
            'can_replay' => true,
            'replay_data' => [
                'pb' => $reconstructedInputs['PB']['original'] ?? null,
                'po' => $reconstructedInputs['PO']['original'] ?? null,
                'indices' => array_filter($reconstructedInputs, fn($k) => str_starts_with($k, 'F') || str_starts_with($k, 'I'), ARRAY_FILTER_USE_KEY),
                'bids' => array_filter($reconstructedInputs, fn($k) => $k === 'PI', ARRAY_FILTER_USE_KEY),
            ],
        ];
    }


    /**
     * دریافت نوع پیام از شناسه
     */
    private function getMessageType(string $messageId): string
    {
        if (str_starts_with($messageId, 'E')) {
            return 'ERROR';
        } elseif (str_starts_with($messageId, 'W')) {
            return 'WARNING';
        } elseif (str_starts_with($messageId, 'I')) {
            return 'INFO';
        }
        return 'INFO';
    }

    /**
     * دریافت خلاصه تاریخچه
     */
    public function getHistorySummary(string $tenderId): array
    {
        $auditTrails = AuditTrail::getByTenderId($tenderId);
        
        $summary = [
            'total_entries' => count($auditTrails),
            'by_action_type' => [],
            'by_stage' => [],
            'by_decision_path' => [],
            'errors_count' => 0,
            'warnings_count' => 0,
            'info_count' => 0,
        ];

        foreach ($auditTrails as $trail) {
            // شمارش بر اساس نوع عمل
            $actionType = $trail['action_type'];
            $summary['by_action_type'][$actionType] = ($summary['by_action_type'][$actionType] ?? 0) + 1;

            // شمارش بر اساس مرحله
            $stage = $trail['stage'];
            $summary['by_stage'][$stage] = ($summary['by_stage'][$stage] ?? 0) + 1;

            // شمارش بر اساس مسیر تصمیم
            if ($trail['decision_path']) {
                $decisionPath = $trail['decision_path'];
                $summary['by_decision_path'][$decisionPath] = ($summary['by_decision_path'][$decisionPath] ?? 0) + 1;
            }

            // شمارش پیام‌ها
            if ($trail['message_type'] === 'ERROR') {
                $summary['errors_count']++;
            } elseif ($trail['message_type'] === 'WARNING') {
                $summary['warnings_count']++;
            } elseif ($trail['message_type'] === 'INFO') {
                $summary['info_count']++;
            }
        }

        return $summary;
    }
}


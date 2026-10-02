<?php

namespace App\Services;

use App\Models\Tender;
use App\Services\AlertNotificationService;
use App\Services\DataValidationService;

/**
 * سرویس سلسله‌مراتب تصمیم‌گیری
 * 
 * این سرویس منطق تصمیم‌گیری را بر اساس سلسله‌مراتب شروط و مسیرها مدیریت می‌کند
 */
class DecisionHierarchyService
{
    private $alertService;
    private $validationService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
        $this->validationService = new DataValidationService();
    }

    /**
     * تصمیم‌گیری بر اساس سلسله‌مراتب شروط
     * 
     * @param string $tenderId
     * @param string $stage مرحله فعلی
     * @return array ['can_proceed' => bool, 'blocking_errors' => [], 'warnings' => [], 'next_stage' => string|null]
     */
    public function decide(string $tenderId, string $stage): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'can_proceed' => false,
                'blocking_errors' => ['مناقصه یافت نشد'],
                'warnings' => [],
                'next_stage' => null,
            ];
        }

        // اعتبارسنجی جامع
        $validation = $this->validationService->validateTender($tenderId);

        // بررسی سلسله‌مراتب بر اساس مرحله
        $decision = match ($stage) {
            'PB_INPUT' => $this->decidePbStage($tenderId, $tender, $validation),
            'INDEX_INPUT' => $this->decideIndexStage($tenderId, $tender, $validation),
            'PO_CALCULATION' => $this->decidePoStage($tenderId, $tender, $validation),
            'PI_INPUT' => $this->decidePiStage($tenderId, $tender, $validation),
            'EVALUATION' => $this->decideEvaluationStage($tenderId, $tender, $validation),
            default => [
                'can_proceed' => !$validation['blocking'],
                'blocking_errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'next_stage' => null,
            ],
        };

        return $decision;
    }

    /**
     * تصمیم‌گیری در مرحله ورودی Pb
     */
    private function decidePbStage(string $tenderId, Tender $tender, array $validation): array
    {
        $blockingErrors = [];
        $warnings = [];

        // اولویت 1: بررسی وجود Pb
        $pb = \App\Models\Estimate::calculateTotalPb($tenderId);
        if ($pb <= 0) {
            $blockingErrors[] = 'Pb باید مقدار مثبت داشته باشد';
            $this->alertService->sendWarning('W001', $tenderId, ['pb' => $pb]);
            return [
                'can_proceed' => false,
                'blocking_errors' => $blockingErrors,
                'warnings' => $warnings,
                'next_stage' => null,
            ];
        }

        // اولویت 2: بررسی منطقی بودن مقدار
        if ($pb < 1000 || $pb > 1000000000000) {
            $warnings[] = 'مقدار Pb خارج از محدوده منطقی است';
        }

        return [
            'can_proceed' => true,
            'blocking_errors' => [],
            'warnings' => $warnings,
            'next_stage' => 'INDEX_INPUT',
        ];
    }

    /**
     * تصمیم‌گیری در مرحله ورودی شاخص‌ها
     */
    private function decideIndexStage(string $tenderId, Tender $tender, array $validation): array
    {
        $blockingErrors = [];
        $warnings = [];

        $poMethod = $tender->po_method ?? '1';
        $indices = \App\Models\Index::getByTenderId($tenderId);

        // اولویت 1: بررسی وجود شاخص‌های مورد نیاز
        $requiredIndices = match ($poMethod) {
            '1' => ['I1', 'I2', 'I3', 'r1', 'r2'],
            '2' => ['F1', 'z'],
            '3' => ['F1', 'z'],
            default => [],
        };

        $indicesMap = [];
        foreach ($indices as $index) {
            $indicesMap[$index['type']] = $index['value'];
        }

        $missingIndices = [];
        foreach ($requiredIndices as $indexType) {
            if (!isset($indicesMap[$indexType]) || $indicesMap[$indexType] <= 0) {
                $missingIndices[] = $indexType;
            }
        }

        if (!empty($missingIndices)) {
            $blockingErrors[] = 'شاخص‌های مورد نیاز ناقص هستند: ' . implode(', ', $missingIndices);
            $this->alertService->sendWarning('W002', $tenderId, ['missing_indices' => $missingIndices]);
            return [
                'can_proceed' => false,
                'blocking_errors' => $blockingErrors,
                'warnings' => $warnings,
                'next_stage' => null,
            ];
        }

        // اولویت 2: بررسی ناسازگاری (برای روش 2 و 3)
        if ($poMethod === '2' || $poMethod === '3') {
            $inconsistency = $this->alertService->checkIndexInconsistency($tenderId, $poMethod);
            if ($inconsistency) {
                $warnings[] = 'شاخص‌های تعدیل تاریخی ناسازگار هستند';
            }
        }

        return [
            'can_proceed' => true,
            'blocking_errors' => [],
            'warnings' => $warnings,
            'next_stage' => 'PO_CALCULATION',
        ];
    }

    /**
     * تصمیم‌گیری در مرحله محاسبه Po
     */
    private function decidePoStage(string $tenderId, Tender $tender, array $validation): array
    {
        $blockingErrors = [];
        $warnings = [];

        $po = floatval($tender->po ?? 0);
        $pb = \App\Models\Estimate::calculateTotalPb($tenderId);

        // اولویت 1: بررسی وجود Po
        if ($po <= 0) {
            $blockingErrors[] = 'Po باید محاسبه شده باشد';
            $this->alertService->sendError('E001', $tenderId, ['po' => $po]);
            return [
                'can_proceed' => false,
                'blocking_errors' => $blockingErrors,
                'warnings' => $warnings,
                'next_stage' => null,
            ];
        }

        // اولویت 2: بررسی محدوده ناهماهنگ
        if ($pb > 0) {
            $unreasonable = $this->alertService->checkUnreasonablePoRange($tenderId, $pb, $po);
            if ($unreasonable) {
                $warnings[] = 'محدوده Po نسبت به Pb غیرمنطقی است';
            }
        }

        // اولویت 3: بررسی مناقصه دو مرحله‌ای
        if ($tender->is_two_stage) {
            $bidders = \App\Models\Bidder::getByTenderId($tenderId);
            $hasTechnicalScores = false;
            foreach ($bidders as $bidder) {
                if (floatval($bidder['technical_score'] ?? 0) > 0) {
                    $hasTechnicalScores = true;
                    break;
                }
            }

            if (!$hasTechnicalScores) {
                $warnings[] = 'برای مناقصه دو مرحله‌ای، امتیاز فنی-بازرگانی الزامی است';
                $this->alertService->sendWarning('W003', $tenderId, [
                    'is_two_stage' => true,
                    'prices_adjusted' => false,
                ]);
            }
        }

        return [
            'can_proceed' => true,
            'blocking_errors' => [],
            'warnings' => $warnings,
            'next_stage' => 'PI_INPUT',
        ];
    }

    /**
     * تصمیم‌گیری در مرحله ورودی Pi
     */
    private function decidePiStage(string $tenderId, Tender $tender, array $validation): array
    {
        $blockingErrors = [];
        $warnings = [];

        $bidders = \App\Models\Bidder::getByTenderId($tenderId);
        $po = floatval($tender->po ?? 0);

        // اولویت 1: بررسی وجود پیشنهادات
        if (empty($bidders)) {
            $warnings[] = 'هیچ پیشنهادی ثبت نشده است';
            return [
                'can_proceed' => false,
                'blocking_errors' => [],
                'warnings' => $warnings,
                'next_stage' => null,
            ];
        }

        // اولویت 2: بررسی اعتبار پیشنهادات
        foreach ($bidders as $bidder) {
            $price = floatval($bidder['price'] ?? 0);
            if ($price <= 0) {
                $blockingErrors[] = "قیمت پیشنهادی {$bidder['name']} نامعتبر است";
                $this->alertService->sendWarning('W004', $tenderId, [
                    'bidder_id' => $bidder['id'],
                    'bidder_name' => $bidder['name'],
                ]);
            }

            // بررسی نسبت به Po
            if ($po > 0 && $price > 3 * $po) {
                $warnings[] = "قیمت پیشنهادی {$bidder['name']} بیش از 3 برابر Po است";
            }
        }

        if (!empty($blockingErrors)) {
            return [
                'can_proceed' => false,
                'blocking_errors' => $blockingErrors,
                'warnings' => $warnings,
                'next_stage' => null,
            ];
        }

        return [
            'can_proceed' => true,
            'blocking_errors' => [],
            'warnings' => $warnings,
            'next_stage' => 'EVALUATION',
        ];
    }

    /**
     * تصمیم‌گیری در مرحله ارزیابی
     */
    private function decideEvaluationStage(string $tenderId, Tender $tender, array $validation): array
    {
        $blockingErrors = [];
        $warnings = [];

        $po = floatval($tender->po ?? 0);
        $bidders = \App\Models\Bidder::getByTenderId($tenderId);

        // اولویت 1: بررسی Po
        if ($po <= 0) {
            $blockingErrors[] = 'Po باید محاسبه شده باشد';
            $this->alertService->sendError('E003', $tenderId, ['po' => $po]);
            return [
                'can_proceed' => false,
                'blocking_errors' => $blockingErrors,
                'warnings' => $warnings,
                'next_stage' => null,
            ];
        }

        // اولویت 2: بررسی پیشنهادات
        if (empty($bidders)) {
            $blockingErrors[] = 'هیچ پیشنهادی برای ارزیابی وجود ندارد';
            return [
                'can_proceed' => false,
                'blocking_errors' => $blockingErrors,
                'warnings' => $warnings,
                'next_stage' => null,
            ];
        }

        // اولویت 3: بررسی ورودی‌های ناقص ترکیبی
        $combinedErrors = $this->alertService->checkCombinedErrors($tenderId, [
            'pi_complete' => !empty($bidders),
            'po' => $po,
            'n' => count($bidders),
        ]);

        if (!empty($combinedErrors)) {
            foreach ($combinedErrors as $error) {
                if ($error['type'] === AlertNotificationService::TYPE_ERROR) {
                    $blockingErrors[] = $error['message'];
                } else {
                    $warnings[] = $error['message'];
                }
            }
        }

        // اولویت 4: بررسی مناقصه دو مرحله‌ای
        if ($tender->is_two_stage) {
            $hasTechnicalScores = false;
            foreach ($bidders as $bidder) {
                if (floatval($bidder['technical_score'] ?? 0) > 0) {
                    $hasTechnicalScores = true;
                    break;
                }
            }

            if (!$hasTechnicalScores) {
                $blockingErrors[] = 'برای مناقصه دو مرحله‌ای، امتیاز فنی-بازرگانی الزامی است';
                $this->alertService->sendError('E006', $tenderId, [
                    'is_two_stage' => true,
                    'technical_stage_complete' => false,
                ]);
            }
        }

        return [
            'can_proceed' => empty($blockingErrors),
            'blocking_errors' => $blockingErrors,
            'warnings' => $warnings,
            'next_stage' => null,
        ];
    }
}


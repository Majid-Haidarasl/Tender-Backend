<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Services\AlertNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * چارچوب جامع اعتبارسنجی ورودی‌ها و مدیریت پیام‌ها
 * مطابق دستورالعمل ارزیابی مالی مناقصات وزارت نفت
 */
class ComprehensiveValidationFramework
{
    private $alertService;
    private $validationService;
    private $hierarchyService;

    // دسته‌بندی ورودی‌ها
    const INPUT_CATEGORY_DOCUMENTS = 'documents';
    const INPUT_CATEGORY_ESTIMATES = 'estimates';
    const INPUT_CATEGORY_INDICES = 'indices';
    const INPUT_CATEGORY_TENDER_PARAMS = 'tender_params';
    const INPUT_CATEGORY_COMPUTATIONAL = 'computational';

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
        $this->validationService = new DataValidationService();
        $this->hierarchyService = new DecisionHierarchyService();
    }

    /**
     * اعتبارسنجی جامع تمام ورودی‌ها
     * 
     * @param string $tenderId
     * @return array ['valid' => bool, 'results' => [], 'blocking_errors' => [], 'warnings' => [], 'info' => []]
     */
    public function validateAllInputs(string $tenderId): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'valid' => false,
                'results' => [],
                'blocking_errors' => ['مناقصه یافت نشد'],
                'warnings' => [],
                'info' => [],
            ];
        }

        // ثبت شروع اعتبارسنجی در Audit Trail
        $auditTrailService = new \App\Services\AuditTrailService();
        $auditTrailService->logMessage(
            $tenderId,
            'VALIDATION_START',
            'INFO',
            'اعتبارسنجی جامع',
            null,
            0,
            false,
            ['timestamp' => now()->toDateTimeString()]
        );

        $results = [];
        $blockingErrors = [];
        $warnings = [];
        $info = [];

        // 1. اعتبارسنجی اسناد و اطلاعات مناقصه
        $documentsValidation = $this->validateDocuments($tenderId, $tender, true);
        $results['documents'] = $documentsValidation;
        if (!$documentsValidation['valid']) {
            $blockingErrors = array_merge($blockingErrors, $documentsValidation['errors']);
            $warnings = array_merge($warnings, $documentsValidation['warnings']);
        }

        // 2. اعتبارسنجی برآوردها
        $estimatesValidation = $this->validateEstimates($tenderId, $tender);
        $results['estimates'] = $estimatesValidation;
        if (!$estimatesValidation['valid']) {
            $blockingErrors = array_merge($blockingErrors, $estimatesValidation['errors']);
            $warnings = array_merge($warnings, $estimatesValidation['warnings']);
        }

        // 3. اعتبارسنجی شاخص‌ها
        $indicesValidation = $this->validateIndicesComprehensive($tenderId, $tender);
        $results['indices'] = $indicesValidation;
        if (!$indicesValidation['valid']) {
            $blockingErrors = array_merge($blockingErrors, $indicesValidation['errors']);
            $warnings = array_merge($warnings, $indicesValidation['warnings']);
        }

        // 4. اعتبارسنجی پارامترهای مناقصه‌گزار
        $paramsValidation = $this->validateTenderParameters($tenderId, $tender);
        $results['tender_params'] = $paramsValidation;
        if (!$paramsValidation['valid']) {
            $blockingErrors = array_merge($blockingErrors, $paramsValidation['errors']);
            $warnings = array_merge($warnings, $paramsValidation['warnings']);
        }

        // 5. اعتبارسنجی سازگاری داده‌ها
        $compatibilityValidation = $this->validateDataCompatibility($tenderId, $tender);
        $results['compatibility'] = $compatibilityValidation;
        if (!$compatibilityValidation['valid']) {
            $blockingErrors = array_merge($blockingErrors, $compatibilityValidation['errors']);
            $warnings = array_merge($warnings, $compatibilityValidation['warnings']);
        }

        // 6. اعتبارسنجی مسیرهای محاسباتی
        $pathValidation = $this->validateCalculationPaths($tenderId, $tender);
        $results['calculation_paths'] = $pathValidation;
        if (!$pathValidation['valid']) {
            $warnings = array_merge($warnings, $pathValidation['warnings']);
            $info = array_merge($info, $pathValidation['info']);
        }

        // 7. اعتبارسنجی دو مرحله‌ای
        if ($tender->is_two_stage) {
            $twoStageValidation = $this->validateTwoStage($tenderId, $tender);
            $results['two_stage'] = $twoStageValidation;
            if (!$twoStageValidation['valid']) {
                $blockingErrors = array_merge($blockingErrors, $twoStageValidation['errors']);
                $warnings = array_merge($warnings, $twoStageValidation['warnings']);
                $info = array_merge($info, $twoStageValidation['info']);
            }
        }

        // 8. اعتبارسنجی شاخص‌های تاریخی و پیش‌بینی
        $historicalValidation = $this->validateHistoricalIndices($tenderId, $tender);
        $results['historical_indices'] = $historicalValidation;
        if (!$historicalValidation['valid']) {
            $blockingErrors = array_merge($blockingErrors, $historicalValidation['errors']);
            $warnings = array_merge($warnings, $historicalValidation['warnings']);
        }

        // 9. اعتبارسنجی ضریب‌های تعدیل
        $coefficientsValidation = $this->validateAdjustmentCoefficients($tenderId, $tender);
        $results['coefficients'] = $coefficientsValidation;
        if (!$coefficientsValidation['valid']) {
            $blockingErrors = array_merge($blockingErrors, $coefficientsValidation['errors']);
            $warnings = array_merge($warnings, $coefficientsValidation['warnings']);
        }

        // 10. اعتبارسنجی دامنه قیمت‌های نرمال‌شده (اگر ارزیابی انجام شده)
        $hasEvaluationCalculatedAt = \Illuminate\Support\Facades\Schema::hasColumn('tenders', 'evaluation_calculated_at');
        if ($hasEvaluationCalculatedAt && $tender->evaluation_calculated_at) {
            $normalizedValidation = $this->validateNormalizedPrices($tenderId, $tender);
            $results['normalized_prices'] = $normalizedValidation;
            if (!$normalizedValidation['valid']) {
                $warnings = array_merge($warnings, $normalizedValidation['warnings']);
            }
        }

        // 11. مدیریت تصمیم نهایی
        $finalDecisionValidation = $this->validateFinalDecision($tenderId, $tender);
        $results['final_decision'] = $finalDecisionValidation;
        if (!$finalDecisionValidation['valid']) {
            $info = array_merge($info, $finalDecisionValidation['info']);
        }

        // ثبت پایان اعتبارسنجی در Audit Trail
        $auditTrailService = new \App\Services\AuditTrailService();
        $auditTrailService->logMessage(
            $tenderId,
            'VALIDATION_END',
            empty($blockingErrors) ? 'INFO' : 'ERROR',
            'اعتبارسنجی جامع',
            null,
            empty($blockingErrors) ? 0 : 100,
            !empty($blockingErrors),
            [
                'valid' => empty($blockingErrors),
                'blocking_errors_count' => count($blockingErrors),
                'warnings_count' => count($warnings),
                'info_count' => count($info),
            ]
        );

        return [
            'valid' => empty($blockingErrors),
            'results' => $results,
            'blocking_errors' => $blockingErrors,
            'warnings' => $warnings,
            'info' => $info,
        ];
    }

    /**
     * 1. اعتبارسنجی اسناد و اطلاعات مناقصه
     */
    private function validateDocuments(string $tenderId, Tender $tender, bool $strictMode = true): array
    {
        $errors = [];
        $warnings = [];

        // بررسی عنوان مناقصه (همیشه الزامی است)
        if (empty($tender->title)) {
            $errors[] = 'عنوان مناقصه الزامی است';
            $this->alertService->sendWarning('W001', $tenderId, [
                'missing' => 'عنوان مناقصه',
            ]);
        }

        // بررسی شرح کار (فقط در حالت strict یا برای ارزیابی)
        if ($strictMode && empty($tender->description)) {
            $warnings[] = 'شرح کار و فهرست بها ناقص است. توصیه می‌شود برای تکمیل اطلاعات، شرح کار را وارد کنید.';
            $this->alertService->sendWarning('W001', $tenderId, [
                'missing' => 'شرح کار',
            ]);
        } elseif (!$strictMode && empty($tender->description)) {
            // در حالت غیر strict، فقط warning است
            $warnings[] = 'شرح کار و فهرست بها ناقص است';
        }

        // بررسی نوع مناقصه
        if (empty($tender->type)) {
            $warnings[] = 'نوع مناقصه مشخص نشده است';
        }

        // بررسی بخش‌های اصلی کار
        $estimates = Estimate::getByTenderId($tenderId);
        if (empty($estimates)) {
            $errors[] = 'بخش‌های اصلی کار (طراحی، تأمین، نصب، خدمات پشتیبانی) تعریف نشده‌اند';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 2. اعتبارسنجی برآوردها
     */
    private function validateEstimates(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];

        // اعتبارسنجی کامل بودن داده‌ها
        $pb = Estimate::calculateTotalPb($tenderId);
        if ($pb <= 0) {
            $errors[] = 'خطا: ورودی Pb ناقص است. فرآیند متوقف شد.';
            $this->alertService->sendWarning('W001', $tenderId, ['pb' => $pb]);
        }

        // بررسی نوع و قالب داده‌ها
        if ($pb > 0 && !is_numeric($pb)) {
            $errors[] = 'خطا: مقدار Pb باید عددی باشد';
        }

        if ($pb > 0 && ($pb < 1000 || $pb > 1000000000000)) {
            $warnings[] = 'هشدار: مقدار Pb غیرعادی است. لطفاً بررسی شود.';
        }

        // بررسی Po
        $po = floatval($tender->po ?? 0);
        if ($po > 0) {
            if (!is_numeric($po)) {
                $errors[] = 'خطا: مقدار Po باید عددی باشد';
            }

            if ($po < 1000 || $po > 1000000000000) {
                $warnings[] = 'هشدار: مقدار Po غیرعادی است. لطفاً بررسی شود.';
            }
        }

        // بررسی Pi
        $bidders = Bidder::getByTenderId($tenderId);
        foreach ($bidders as $bidder) {
            $pi = floatval($bidder['price'] ?? 0);
            if ($pi <= 0) {
                $errors[] = "خطا: ورودی Pi (پیشنهاد {$bidder['name']}) ناقص است. فرآیند متوقف شد.";
                $this->alertService->sendWarning('W004', $tenderId, [
                    'bidder_id' => $bidder['id'],
                    'bidder_name' => $bidder['name'],
                ]);
            } elseif (!is_numeric($pi)) {
                $errors[] = "خطا: مقدار Pi (پیشنهاد {$bidder['name']}) باید عددی باشد";
            } elseif ($pi < 1000 || $pi > 1000000000000) {
                $warnings[] = "هشدار: مقدار Pi (پیشنهاد {$bidder['name']}) غیرعادی است. لطفاً بررسی شود.";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 3. اعتبارسنجی جامع شاخص‌ها
     */
    private function validateIndicesComprehensive(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];

        $indices = Index::getByTenderId($tenderId);
        $indicesMap = [];
        foreach ($indices as $index) {
            $indicesMap[$index['type']] = $index;
        }

        $poMethod = $tender->po_method ?? '1';

        // بررسی شاخص‌های مورد نیاز بر اساس روش
        $requiredIndices = match ($poMethod) {
            '1' => ['I1', 'I2', 'I3', 'r1', 'r2'],
            '2' => ['F1', 'z'],
            '3' => ['F1', 'z'],
            default => [],
        };

        // اعتبارسنجی کامل بودن داده‌ها
        foreach ($requiredIndices as $indexType) {
            if (!isset($indicesMap[$indexType]) || $indicesMap[$indexType]['value'] <= 0) {
                $errors[] = "خطا: ورودی {$indexType} ناقص است. فرآیند متوقف شد.";
                $this->alertService->sendWarning('W002', $tenderId, [
                    'missing_indices' => [$indexType],
                ]);
            }
        }

        // بررسی نوع و قالب داده‌ها
        foreach ($indicesMap as $type => $index) {
            $value = floatval($index['value'] ?? 0);
            
            if (!is_numeric($value)) {
                $errors[] = "خطا: مقدار شاخص {$type} باید عددی باشد";
            }

            // بررسی بازه منطقی (0.01 تا 10000)
            if ($value > 0 && ($value < 0.01 || $value > 10000)) {
                $warnings[] = "هشدار: مقدار شاخص {$type} غیرعادی است. لطفاً بررسی شود.";
            }
        }

        // بررسی f1 تا f9 برای روش 2 و 3
        if ($poMethod === '2' || $poMethod === '3') {
            $fIndices = [];
            for ($i = 1; $i <= 9; $i++) {
                $fKey = "f{$i}";
                if (isset($indicesMap[$fKey]) && $indicesMap[$fKey]['value'] > 0) {
                    $fIndices[$i] = $indicesMap[$fKey];
                }
            }

            if (count($fIndices) < 2) {
                $errors[] = 'خطا: داده‌های شاخص‌های تاریخی ناقص هستند. امکان محاسبه F\'2 و F\'3 وجود ندارد.';
                $this->alertService->sendError('E005', $tenderId, [
                    'method' => $poMethod,
                    'f_indices_count' => count($fIndices),
                ]);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 4. اعتبارسنجی پارامترهای مناقصه‌گزار
     */
    private function validateTenderParameters(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];

        // بررسی روش محاسبه Po
        $poMethod = $tender->po_method ?? '1';
        if (!in_array($poMethod, ['1', '2', '3'])) {
            $errors[] = 'خطا: روش محاسبه Po نامعتبر است';
        }

        // بررسی بازه‌های قیمت قابل قبول
        // این بررسی در مرحله ارزیابی انجام می‌شود

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 5. اعتبارسنجی سازگاری داده‌ها
     */
    private function validateDataCompatibility(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];

        $pb = Estimate::calculateTotalPb($tenderId);
        $po = floatval($tender->po ?? 0);
        $bidders = Bidder::getByTenderId($tenderId);

        // بررسی برآورد اولیه با شاخص‌های تعدیل
        if ($pb > 0 && $po > 0) {
            $ratio = $po / $pb;
            
            // اگر Po < Pb یا Po بیش از حد نرمال شده
            if ($ratio < 0.5 || $ratio > 2.0) {
                $errors[] = 'خطا: عدم سازگاری بین برآورد به‌هنگام و پیشنهادهای مناقصه‌گران تشخیص داده شد.';
                $this->alertService->checkUnreasonablePoRange($tenderId, $pb, $po);
            }
        }

        // تداخل مقادیر Pi و Po
        if ($po > 0 && !empty($bidders)) {
            foreach ($bidders as $bidder) {
                $pi = floatval($bidder['price'] ?? 0);
                if ($pi > 0 && $pi > 3 * $po) {
                    $warnings[] = "هشدار: قیمت پیشنهادی {$bidder['name']} بیش از 3 برابر Po است";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 6. اعتبارسنجی مسیرهای محاسباتی
     */
    private function validateCalculationPaths(string $tenderId, Tender $tender): array
    {
        $warnings = [];
        $info = [];

        $bidders = Bidder::getByTenderId($tenderId);
        $n = count($bidders);
        $po = floatval($tender->po ?? 0);

        if ($n === 0 || $po <= 0) {
            return [
                'valid' => true,
                'warnings' => [],
                'info' => [],
            ];
        }

        // محاسبه میانگین
        $prices = array_filter(array_column($bidders, 'price'), fn($p) => $p > 0);
        $m = !empty($prices) ? array_sum($prices) / count($prices) : 0;

        // تعیین مسیر
        $selectedPath = null;
        if ($n <= 2) {
            $selectedPath = 'SIMPLE';
            $this->alertService->sendWarning('W005', $tenderId, ['n' => $n]);
        } elseif ($n >= 4) {
            $lowerBound = 0.8 * $po;
            $upperBound = 1.35 * $po;
            
            if ($m < $lowerBound || $m > $upperBound) {
                $selectedPath = 'SUSPENDED';
                $this->alertService->sendWarning('W006', $tenderId, [
                    'mean' => $m,
                    'lower_bound' => $lowerBound,
                    'upper_bound' => $upperBound,
                ]);
            } else {
                $selectedPath = 'STATISTICAL';
                $this->alertService->sendInfo('I001', $tenderId, ['mean' => $m]);
            }
        }

        // بررسی سازگاری مسیر با داده‌ها
        if ($selectedPath === 'STATISTICAL' && $n < 4) {
            $warnings[] = 'هشدار: تعداد پیشنهادها و میانگین آن با مسیر انتخاب شده تطابق ندارد. مسیر محاسبات اصلاح شود.';
        }

        $info[] = "مسیر محاسباتی انتخاب شده: {$selectedPath}";

        return [
            'valid' => true,
            'warnings' => $warnings,
            'info' => $info,
            'selected_path' => $selectedPath,
        ];
    }

    /**
     * 7. اعتبارسنجی دو مرحله‌ای
     */
    private function validateTwoStage(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];
        $info = [];

        $bidders = Bidder::getByTenderId($tenderId);
        
        // بررسی امتیاز فنی-بازرگانی (فقط اگر مناقصه‌گران وجود داشته باشند)
        if (!empty($bidders)) {
            $hasTechnicalScores = false;
            foreach ($bidders as $bidder) {
                $technicalScore = floatval($bidder['technical_score'] ?? 0);
                if ($technicalScore > 0 && $technicalScore <= 100) {
                    $hasTechnicalScores = true;
                    break;
                }
            }

            if (!$hasTechnicalScores) {
                $errors[] = 'خطا: برای مناقصه دو مرحله‌ای، امتیاز فنی-بازرگانی الزامی است';
                $this->alertService->sendError('E006', $tenderId, [
                    'is_two_stage' => true,
                    'technical_stage_complete' => false,
                ]);
            }
        }

        $info[] = 'اطلاع: مناقصه دو مرحله‌ای است. محاسبه امتیاز فنی و تراز قیمت‌ها الزامی است.';
        $this->alertService->sendWarning('W003', $tenderId, [
            'is_two_stage' => true,
        ]);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
        ];
    }

    /**
     * 8. اعتبارسنجی شاخص‌های تاریخی و پیش‌بینی
     */
    private function validateHistoricalIndices(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];

        $poMethod = $tender->po_method ?? '1';
        
        if ($poMethod !== '2' && $poMethod !== '3') {
            return [
                'valid' => true,
                'errors' => [],
                'warnings' => [],
            ];
        }

        $indices = Index::getByTenderId($tenderId);
        $indicesMap = [];
        foreach ($indices as $index) {
            $indicesMap[$index['type']] = $index;
        }

        // بررسی وجود f1 تا f9
        $fIndices = [];
        for ($i = 1; $i <= 9; $i++) {
            $fKey = "f{$i}";
            if (isset($indicesMap[$fKey]) && $indicesMap[$fKey]['value'] > 0) {
                $fIndices[$i] = $indicesMap[$fKey];
            }
        }

        if (count($fIndices) < 2) {
            $errors[] = 'خطا: داده‌های شاخص‌های تاریخی ناقص هستند. امکان محاسبه F\'2 و F\'3 وجود ندارد.';
            $this->alertService->sendError('E005', $tenderId, [
                'method' => $poMethod,
                'f_indices_count' => count($fIndices),
            ]);
        }

        // بررسی ناسازگاری
        $inconsistency = $this->alertService->checkIndexInconsistency($tenderId, $poMethod);
        if ($inconsistency) {
            $warnings[] = 'هشدار: شاخص‌های تعدیل تاریخی ناسازگار هستند';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 9. اعتبارسنجی ضریب‌های تعدیل
     */
    private function validateAdjustmentCoefficients(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];

        $isAdjustable = $tender->is_adjustable ?? false;
        $po = floatval($tender->po ?? 0);
        $pb = Estimate::calculateTotalPb($tenderId);

        if ($po > 0 && $pb > 0) {
            $ratio = $po / $pb;

            if ($isAdjustable) {
                // کار تعدیل‌پذیر - باید β تعریف شده باشد
                if ($ratio < 0.5 || $ratio > 2.0) {
                    $errors[] = 'خطا: ضریب تعدیل β برای نوع کار انتخاب شده تعریف نشده است.';
                }
            } else {
                // کار فاقد تعدیل - باید γ تعریف شده باشد
                if ($ratio < 0.5 || $ratio > 2.0) {
                    $errors[] = 'خطا: ضریب تعدیل γ برای نوع کار انتخاب شده تعریف نشده است.';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 10. اعتبارسنجی دامنه قیمت‌های نرمال‌شده
     */
    private function validateNormalizedPrices(string $tenderId, Tender $tender): array
    {
        $warnings = [];

        // دریافت نتایج ارزیابی
        $evaluationService = new EvaluationService();
        try {
            $evaluationResult = $evaluationService->getResults($tenderId);
            $results = $evaluationResult['results'] ?? [];

            $outOfRangeCount = 0;
            foreach ($results as $result) {
                $normalized = $result['statistically_normalized'] ?? 0;
                
                // بررسی شرط -1 ≤ P'i ≤ 1
                if ($normalized < -1 || $normalized > 1) {
                    $outOfRangeCount++;
                }
            }

            if ($outOfRangeCount > 0) {
                $warnings[] = "هشدار: برخی پیشنهادهای نرمال‌شده خارج از دامنه مجاز هستند. بررسی دستی لازم است.";
                $this->alertService->sendWarning('W008', $tenderId, [
                    'out_of_range_count' => $outOfRangeCount,
                ]);
            }

            // بررسی مقادیر شدید
            $normalizedValues = array_column($results, 'statistically_normalized');
            $extremeCheck = $this->alertService->checkExtremeNormalizedValues($tenderId, $normalizedValues);
            if ($extremeCheck) {
                $warnings[] = 'هشدار: مقادیر نرمال‌شده خارج از حد منطقی شدید هستند';
            }

        } catch (\Exception $e) {
            // اگر ارزیابی انجام نشده باشد، خطا نمی‌دهیم
        }

        return [
            'valid' => true,
            'warnings' => $warnings,
        ];
    }

    /**
     * 11. مدیریت تصمیم نهایی
     */
    private function validateFinalDecision(string $tenderId, Tender $tender): array
    {
        $info = [];

        // دریافت نتایج ارزیابی
        $evaluationService = new EvaluationService();
        try {
            $evaluationResult = $evaluationService->getResults($tenderId);
            $results = $evaluationResult['results'] ?? [];
            $action = $evaluationResult['action'] ?? '';

            if ($action === 'NO_VALID_BIDS') {
                $info[] = 'اطلاع: هیچ پیشنهادی قابل قبول نیست. فرآیند مناقصه برای تجدید یا لغو متوقف شد.';
            } elseif ($action === 'AWARD') {
                $winner = array_filter($results, fn($r) => $r['is_winner_first'] ?? false);
                if (!empty($winner)) {
                    $winnerData = reset($winner);
                    $info[] = "اطلاع: برنده مناقصه مشخص شد: {$winnerData['bidder_name']}";
                }
            }

        } catch (\Exception $e) {
            // اگر ارزیابی انجام نشده باشد، خطا نمی‌دهیم
        }

        return [
            'valid' => true,
            'info' => $info,
        ];
    }

    /**
     * اعتبارسنجی قبل از محاسبه Po
     */
    /**
     * دریافت خطاهای اعتبارسنجی مختصر برای محاسبه Po
     */
    public function getPoCalculationValidationErrors(string $tenderId): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return ['مناقصه یافت نشد'];
        }

        $blockingErrors = [];

        // 1. بررسی عنوان مناقصه
        if (empty($tender->title)) {
            $blockingErrors[] = 'عنوان مناقصه الزامی است';
        }

        // 2. بررسی برآوردها (Pb)
        $estimates = Estimate::getByTenderId($tenderId);
        if (empty($estimates)) {
            $blockingErrors[] = 'بخش‌های اصلی کار (برآوردها) تعریف نشده‌اند';
        } else {
            $Pb = Estimate::calculateTotalPb($tenderId);
            if ($Pb <= 0) {
                $blockingErrors[] = 'مجموع برآوردها باید بیشتر از صفر باشد';
            }
        }

        // 3. بررسی شاخص‌های مورد نیاز برای محاسبه Po
        $poMethodRaw = $tender->po_method ?? '1';
        $poMethod = (string)$poMethodRaw; // تبدیل به string
        $indices = \App\Models\Index::getByTenderId($tenderId);
        $indicesMap = [];
        foreach ($indices as $index) {
            $indicesMap[$index['type']] = $index;
        }

        // تعیین شاخص‌های الزامی بر اساس روش محاسبه
        $requiredIndices = [];
        if ($poMethod === '1' || $poMethodRaw === 1 || $poMethodRaw === '1') {
            // برای روش اول فقط I1 الزامی است (I2, I3, r1, r2 شرطی هستند)
            $requiredIndices = ['I1'];
        } elseif ($poMethod === '2' || $poMethodRaw === 2 || $poMethodRaw === '2') {
            $requiredIndices = ['F1', 'z'];
        } elseif ($poMethod === '3' || $poMethodRaw === 3 || $poMethodRaw === '3') {
            $requiredIndices = ['F1', 'z'];
        } else {
            // پیش‌فرض: روش 1
            \Log::warning('Unknown po_method value, defaulting to method 1', [
                'tender_id' => $tenderId,
                'po_method_raw' => $poMethodRaw,
                'po_method_string' => $poMethod,
                'type' => gettype($poMethodRaw)
            ]);
            $requiredIndices = ['I1'];
        }

        foreach ($requiredIndices as $indexType) {
            if (!isset($indicesMap[$indexType]) || floatval($indicesMap[$indexType]['value'] ?? 0) <= 0) {
                $blockingErrors[] = "شاخص {$indexType} الزامی است و باید مقدار مثبت داشته باشد";
            }
        }

        // 4. برای روش 2 و 3، بررسی f1-f9
        // فقط اگر F2 اعلام نشده باشد، نیاز به f1-f9 داریم
        if ($poMethod === '2' || $poMethod === '3') {
            $F2 = isset($indicesMap['F2']) ? floatval($indicesMap['F2']['value'] ?? 0) : 0;
            
            // اگر F2 اعلام نشده باشد، نیاز به حداقل 2 شاخص تاریخی f1-f9 داریم
            if ($F2 <= 0) {
                $fIndices = [];
                for ($i = 1; $i <= 9; $i++) {
                    $fKey = "f{$i}";
                    if (isset($indicesMap[$fKey]) && floatval($indicesMap[$fKey]['value'] ?? 0) > 0) {
                        $fIndices[$i] = $indicesMap[$fKey];
                    }
                }

                if (count($fIndices) < 2) {
                    $blockingErrors[] = 'برای روش ' . $poMethod . ' حداقل 2 شاخص تاریخی (f1-f9) الزامی است';
                }
            }
        }

        // 6. بررسی پارامترهای زمانی برای کارهای دارای تعدیل (روش اول)
        if ($poMethod === '1' && $tender->is_adjustable) {
            $T_beta = floatval($tender->tbeta ?? 0);
            if ($T_beta <= 0) {
                $blockingErrors[] = 'برای کارهای دارای تعدیل با روش اول، پارامتر T_β (بازه زمانی برای کارهای دارای تعدیل) الزامی است و باید مقدار مثبت داشته باشد';
                $this->alertService->sendError('E009', $tenderId, [
                    'method' => $poMethod,
                    'is_adjustable' => true,
                    'Tβ' => $T_beta,
                ]);
            }
        }

        // 7. بررسی پارامترهای زمانی برای کارهای فاقد تعدیل (روش اول)
        if ($poMethod === '1' && !$tender->is_adjustable) {
            $T_gamma = floatval($tender->tgamma ?? 0);
            if ($T_gamma <= 0) {
                $blockingErrors[] = 'برای کارهای فاقد تعدیل با روش اول، پارامتر T_γ (بازه زمانی برای کارهای فاقد تعدیل) الزامی است و باید مقدار مثبت داشته باشد';
                $this->alertService->sendError('E010', $tenderId, [
                    'method' => $poMethod,
                    'is_adjustable' => false,
                    'T_gamma' => $T_gamma,
                ]);
            }
        }

        return $blockingErrors;
    }

    public function validateBeforePoCalculation(string $tenderId): bool
    {
        $blockingErrors = $this->getPoCalculationValidationErrors($tenderId);

        if (!empty($blockingErrors)) {
            Log::error('Validation failed before Po calculation', [
                'tender_id' => $tenderId,
                'blocking_errors' => $blockingErrors,
            ]);
            return false;
        }

        return true;
    }

    /**
     * اعتبارسنجی قبل از ارزیابی
     */
    public function validateBeforeEvaluation(string $tenderId): bool
    {
        $validation = $this->validateAllInputs($tenderId);
        
        if (!empty($validation['blocking_errors'])) {
            Log::error('Validation failed before evaluation', [
                'tender_id' => $tenderId,
                'blocking_errors' => $validation['blocking_errors'],
            ]);
            return false;
        }

        return true;
    }

    /**
     * دریافت گزارش جامع اعتبارسنجی
     */
    public function getValidationReport(string $tenderId): array
    {
        $validation = $this->validateAllInputs($tenderId);
        
        return [
            'tender_id' => $tenderId,
            'timestamp' => now()->toDateTimeString(),
            'valid' => $validation['valid'],
            'summary' => [
                'blocking_errors_count' => count($validation['blocking_errors']),
                'warnings_count' => count($validation['warnings']),
                'info_count' => count($validation['info']),
            ],
            'details' => $validation['results'],
            'blocking_errors' => $validation['blocking_errors'],
            'warnings' => $validation['warnings'],
            'info' => $validation['info'],
        ];
    }
}


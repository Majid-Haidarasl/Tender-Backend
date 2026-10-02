<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Services\AlertNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * سرویس اعتبارسنجی دقیق داده‌ها
 * 
 * این سرویس ورودی‌های حساس را با دقت اعتبارسنجی می‌کند و
 * ناسازگاری داده‌ها را شناسایی می‌کند
 */
class DataValidationService
{
    private $alertService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
    }

    /**
     * اعتبارسنجی جامع یک مناقصه
     * 
     * @param string $tenderId
     * @return array ['valid' => bool, 'errors' => [], 'warnings' => [], 'blocking' => bool]
     */
    public function validateTender(string $tenderId): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'valid' => false,
                'errors' => ['مناقصه یافت نشد'],
                'warnings' => [],
                'blocking' => true,
            ];
        }

        $errors = [];
        $warnings = [];
        $blocking = false;

        // 1. اعتبارسنجی Pb
        $pbValidation = $this->validatePb($tenderId);
        if (!$pbValidation['valid']) {
            $errors = array_merge($errors, $pbValidation['errors']);
            $warnings = array_merge($warnings, $pbValidation['warnings']);
            if ($pbValidation['blocking']) {
                $blocking = true;
            }
        }

        // 2. اعتبارسنجی شاخص‌ها
        $indicesValidation = $this->validateIndices($tenderId, $tender->po_method ?? '1');
        if (!$indicesValidation['valid']) {
            $errors = array_merge($errors, $indicesValidation['errors']);
            $warnings = array_merge($warnings, $indicesValidation['warnings']);
            if ($indicesValidation['blocking']) {
                $blocking = true;
            }
        }

        // 3. اعتبارسنجی Po (اگر محاسبه شده)
        if ($tender->po > 0) {
            $poValidation = $this->validatePo($tenderId, $tender);
            if (!$poValidation['valid']) {
                $errors = array_merge($errors, $poValidation['errors']);
                $warnings = array_merge($warnings, $poValidation['warnings']);
                if ($poValidation['blocking']) {
                    $blocking = true;
                }
            }
        }

        // 4. اعتبارسنجی پیشنهادات
        $bidsValidation = $this->validateBids($tenderId);
        if (!$bidsValidation['valid']) {
            $errors = array_merge($errors, $bidsValidation['errors']);
            $warnings = array_merge($warnings, $bidsValidation['warnings']);
            if ($bidsValidation['blocking']) {
                $blocking = true;
            }
        }

        // 5. اعتبارسنجی سناریوهای ترکیبی
        $combinedValidation = $this->validateCombinedScenarios($tenderId, $tender);
        if (!$combinedValidation['valid']) {
            $errors = array_merge($errors, $combinedValidation['errors']);
            $warnings = array_merge($warnings, $combinedValidation['warnings']);
            if ($combinedValidation['blocking']) {
                $blocking = true;
            }
        }

        return [
            'valid' => empty($errors) && !$blocking,
            'errors' => $errors,
            'warnings' => $warnings,
            'blocking' => $blocking,
        ];
    }

    /**
     * اعتبارسنجی Pb
     */
    private function validatePb(string $tenderId): array
    {
        $errors = [];
        $warnings = [];
        $blocking = false;

        $pb = Estimate::calculateTotalPb($tenderId);
        
        if ($pb <= 0) {
            $errors[] = 'Pb باید مقدار مثبت داشته باشد';
            $this->alertService->sendWarning('W001', $tenderId, ['pb' => $pb]);
            $blocking = true;
        }

        // بررسی منطقی بودن مقدار
        if ($pb > 0 && ($pb < 1000 || $pb > 1000000000000)) { // کمتر از 1000 یا بیشتر از 1000 تریلیون
            $warnings[] = 'مقدار Pb خارج از محدوده منطقی است';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'blocking' => $blocking,
        ];
    }

    /**
     * اعتبارسنجی شاخص‌ها
     */
    private function validateIndices(string $tenderId, string $poMethod): array
    {
        $errors = [];
        $warnings = [];
        $blocking = false;

        $indices = Index::getByTenderId($tenderId);
        $indicesMap = [];
        foreach ($indices as $index) {
            $indicesMap[$index['type']] = $index;
        }

        // بررسی شاخص‌های مورد نیاز بر اساس روش
        $requiredIndices = match ($poMethod) {
            '1' => ['I1', 'I2', 'I3', 'r1', 'r2'],
            '2' => ['F1', 'z'],
            '3' => ['F1', 'z'],
            default => [],
        };

        $missingIndices = [];
        foreach ($requiredIndices as $indexType) {
            if (!isset($indicesMap[$indexType]) || $indicesMap[$indexType]['value'] <= 0) {
                $missingIndices[] = $indexType;
            }
        }

        if (!empty($missingIndices)) {
            $errors[] = 'شاخص‌های مورد نیاز ناقص هستند: ' . implode(', ', $missingIndices);
            $this->alertService->sendWarning('W002', $tenderId, ['missing_indices' => $missingIndices]);
            $blocking = true;
        }

        // بررسی ناسازگاری شاخص‌های f1-f9 (برای روش 2 و 3)
        if ($poMethod === '2' || $poMethod === '3') {
            $inconsistency = $this->alertService->checkIndexInconsistency($tenderId, $poMethod);
            if ($inconsistency) {
                $warnings[] = 'شاخص‌های تعدیل تاریخی ناسازگار هستند';
            }
        }

        // اعتبارسنجی مقادیر شاخص‌ها
        foreach ($indicesMap as $type => $index) {
            $value = floatval($index['value'] ?? 0);
            
            // بررسی مقادیر منفی یا صفر
            if ($value <= 0 && in_array($type, $requiredIndices)) {
                $errors[] = "شاخص {$type} باید مقدار مثبت داشته باشد";
                $blocking = true;
            }

            // بررسی مقادیر غیرمنطقی
            if ($value > 0 && ($value < 0.01 || $value > 10000)) {
                $warnings[] = "مقدار شاخص {$type} خارج از محدوده منطقی است";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'blocking' => $blocking,
        ];
    }

    /**
     * اعتبارسنجی Po
     */
    private function validatePo(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];
        $blocking = false;

        $pb = Estimate::calculateTotalPb($tenderId);
        $po = floatval($tender->po ?? 0);

        if ($po <= 0) {
            $errors[] = 'Po باید مقدار مثبت داشته باشد';
            $this->alertService->sendError('E001', $tenderId, ['po' => $po]);
            $blocking = true;
        }

        // بررسی محدوده ناهماهنگ Po نسبت به Pb
        if ($pb > 0 && $po > 0) {
            $ratio = $po / $pb;
            if ($ratio < 0.5 || $ratio > 2.0) {
                $warnings[] = 'محدوده Po نسبت به Pb غیرمنطقی است';
                $this->alertService->checkUnreasonablePoRange($tenderId, $pb, $po);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'blocking' => $blocking,
        ];
    }

    /**
     * اعتبارسنجی پیشنهادات
     */
    private function validateBids(string $tenderId): array
    {
        $errors = [];
        $warnings = [];
        $blocking = false;

        $bidders = Bidder::getByTenderId($tenderId);
        
        if (empty($bidders)) {
            $warnings[] = 'هیچ پیشنهادی ثبت نشده است';
            return [
                'valid' => true,
                'errors' => [],
                'warnings' => $warnings,
                'blocking' => false,
            ];
        }

        $tender = Tender::find($tenderId);
        $po = floatval($tender->po ?? 0);

        foreach ($bidders as $bidder) {
            $price = floatval($bidder['price'] ?? 0);
            
            if ($price <= 0) {
                $errors[] = "قیمت پیشنهادی {$bidder['name']} نامعتبر است";
                $this->alertService->sendWarning('W004', $tenderId, [
                    'bidder_id' => $bidder['id'],
                    'bidder_name' => $bidder['name'],
                ]);
                $blocking = true;
            }

            // بررسی منطقی بودن قیمت نسبت به Po
            if ($po > 0 && $price > 0) {
                $ratio = $price / $po;
                if ($ratio > 3.0) {
                    $warnings[] = "قیمت پیشنهادی {$bidder['name']} بیش از 3 برابر Po است";
                }
            }

            // بررسی امتیاز فنی برای مناقصات دو مرحله‌ای
            if ($tender->is_two_stage) {
                $technicalScore = floatval($bidder['technical_score'] ?? 0);
                if ($technicalScore <= 0 || $technicalScore > 100) {
                    $errors[] = "امتیاز فنی {$bidder['name']} نامعتبر است";
                    $this->alertService->sendError('E006', $tenderId, [
                        'bidder_id' => $bidder['id'],
                        'bidder_name' => $bidder['name'],
                    ]);
                    $blocking = true;
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'blocking' => $blocking,
        ];
    }

    /**
     * اعتبارسنجی سناریوهای ترکیبی
     */
    private function validateCombinedScenarios(string $tenderId, Tender $tender): array
    {
        $errors = [];
        $warnings = [];
        $blocking = false;

        // بررسی مناقصه دو مرحله‌ای
        if ($tender->is_two_stage) {
            $bidders = Bidder::getByTenderId($tenderId);
            $hasTechnicalScores = false;
            
            foreach ($bidders as $bidder) {
                $technicalScore = floatval($bidder['technical_score'] ?? 0);
                if ($technicalScore > 0) {
                    $hasTechnicalScores = true;
                    break;
                }
            }

            if (!$hasTechnicalScores) {
                $errors[] = 'برای مناقصه دو مرحله‌ای، امتیاز فنی-بازرگانی الزامی است';
                $this->alertService->sendError('E006', $tenderId, [
                    'is_two_stage' => true,
                    'technical_stage_complete' => false,
                ]);
                $blocking = true;
            }
        }

        // بررسی ورودی‌های ناقص ترکیبی
        $pb = Estimate::calculateTotalPb($tenderId);
        $po = floatval($tender->po ?? 0);
        $bidders = Bidder::getByTenderId($tenderId);
        $indices = Index::getByTenderId($tenderId);

        $piIncomplete = empty($bidders);
        $indicesIncomplete = empty($indices);
        $pbIncomplete = $pb <= 0;
        $poIncomplete = $po <= 0;

        if (($piIncomplete && $indicesIncomplete) || ($piIncomplete && $pbIncomplete)) {
            $errors[] = 'ورودی‌های ناقص ترکیبی شناسایی شد';
            $this->alertService->sendError('E004', $tenderId, [
                'pi_complete' => !$piIncomplete,
                'indices_incomplete' => $indicesIncomplete,
                'pb_incomplete' => $pbIncomplete,
            ]);
            $blocking = true;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'blocking' => $blocking,
        ];
    }

    /**
     * اعتبارسنجی β و γ
     */
    public function validateBetaGamma(?float $beta, ?float $gamma, bool $isAdjustable): array
    {
        $errors = [];
        $warnings = [];

        if ($isAdjustable) {
            if ($beta === null || $beta <= 0 || is_nan($beta) || is_infinite($beta)) {
                $errors[] = 'ضریب β نامعتبر است';
            } elseif ($beta < 0.5 || $beta > 2.0) {
                $warnings[] = 'ضریب β خارج از محدوده منطقی است';
            }
        } else {
            if ($gamma === null || $gamma <= 0 || is_nan($gamma) || is_infinite($gamma)) {
                $errors[] = 'ضریب γ نامعتبر است';
            } elseif ($gamma < 0.5 || $gamma > 2.0) {
                $warnings[] = 'ضریب γ خارج از محدوده منطقی است';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * اعتبارسنجی داده‌ها قبل از محاسبه Po
     */
    public function validateBeforePoCalculation(string $tenderId): bool
    {
        $validation = $this->validateTender($tenderId);
        
        if ($validation['blocking']) {
            Log::error('Validation failed before Po calculation', [
                'tender_id' => $tenderId,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ]);
            return false;
        }

        return true;
    }

    /**
     * اعتبارسنجی داده‌ها قبل از ارزیابی
     */
    public function validateBeforeEvaluation(string $tenderId): bool
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return false;
        }

        $validation = $this->validateTender($tenderId);
        
        // بررسی Po
        if ($tender->po <= 0) {
            $this->alertService->sendError('E003', $tenderId, ['po' => $tender->po]);
            return false;
        }

        // بررسی پیشنهادات
        $bidsValidation = $this->validateBids($tenderId);
        if ($bidsValidation['blocking']) {
            return false;
        }

        if ($validation['blocking']) {
            Log::error('Validation failed before evaluation', [
                'tender_id' => $tenderId,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ]);
            return false;
        }

        return true;
    }
}


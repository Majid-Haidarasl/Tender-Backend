<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Formula;
use App\Services\FormulaService;

/**
 * سرویس محاسبه برآورد به‌هنگام (Po)
 * مطابق با دستورالعمل ارزیابی مالی وزارت نفت
 */
class PoCalculationService
{
    private $alertService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
    }

    /**
     * ارسال هشدار/خطا
     */
    private function sendAlert(string $messageId, string $tenderId, array $context = []): void
    {
        try {
            $messageConfig = $this->alertService->getMessage($messageId);
            if ($messageConfig) {
                if ($messageConfig['type'] === AlertNotificationService::TYPE_ERROR) {
                    $this->alertService->sendError($messageId, $tenderId, $context);
                } else {
                    $this->alertService->sendWarning($messageId, $tenderId, $context);
                }
            }
        } catch (\Exception $e) {
            // اگر سرویس هشدار در دسترس نباشد، فقط در لاگ ثبت می‌شود
            \Log::warning("Could not send alert: " . $e->getMessage());
        }
    }

    /**
     * محاسبه Po برای یک مناقصه
     * اگر ورودی‌ها تغییر نکرده‌اند و نتایج قبلی وجود دارد، نتایج قبلی را برمی‌گرداند
     */
    public function calculate(string $tenderId, bool $forceRecalculate = false): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            throw new \Exception('مناقصه یافت نشد');
        }

        // بررسی cache - اگر ورودی‌ها تغییر نکرده‌اند و نتایج قبلی وجود دارد
        if (!$forceRecalculate) {
            $cacheService = new \App\Services\CalculationCacheService();
            $hasInputsChanged = $cacheService->hasPoInputsChanged($tenderId);
            
            $hasPoCalculatedAt = \Illuminate\Support\Facades\Schema::hasColumn('tenders', 'po_calculated_at');
            if (!$hasInputsChanged && (!$hasPoCalculatedAt || ($tender->po_calculated_at && $tender->po > 0))) {
                // نتایج قبلی معتبر است - برگرداندن نتایج قبلی
                \Log::info('Po calculation cache hit', ['tender_id' => $tenderId]);
                
                $indices = Index::getByTenderId($tenderId);
                $indexMap = $this->mapIndices($indices);
                $method = $tender->po_method ?? '1';
                $isAdjustable = $tender->is_adjustable ?? false;
                
                $result = [
                    'Po' => floatval($tender->po),
                    'Pb' => floatval($tender->pb ?? Estimate::calculateTotalPb($tenderId)),
                    'method' => $method,
                    'from_cache' => true,
                ];
                
                // اگر beta یا gamma در InputHistory موجود است، اضافه کن
                $inputHistory = \App\Models\InputHistory::where('tender_id', $tenderId)
                    ->where('input_type', \App\Models\InputHistory::INPUT_PO)
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                if ($inputHistory && isset($inputHistory->metadata['calculation_data'])) {
                    $calcData = $inputHistory->metadata['calculation_data'] ?? [];
                    if (isset($calcData['beta'])) {
                        $result['beta'] = $calcData['beta'];
                    }
                    if (isset($calcData['gamma'])) {
                        $result['gamma'] = $calcData['gamma'];
                    }
                    if (isset($calcData['formula'])) {
                        $result['formula'] = $calcData['formula'];
                    }
                }
                
                return $result;
            }
        }

        // اعتبارسنجی مختصر قبل از محاسبه Po (بدون بررسی description)
        $comprehensiveValidation = new \App\Services\ComprehensiveValidationFramework();
        if (!$comprehensiveValidation->validateBeforePoCalculation($tenderId)) {
            $blockingErrors = $comprehensiveValidation->getPoCalculationValidationErrors($tenderId);
            $errors = implode('; ', $blockingErrors);
            throw new \Exception("اعتبارسنجی داده‌ها ناموفق بود: {$errors}");
        }

        // محاسبه Pb از برآوردها
        $Pb = Estimate::calculateTotalPb($tenderId);
        if ($Pb <= 0) {
            // ارسال هشدار W001
            $this->sendAlert('W001', $tenderId, ['pb' => $Pb]);
            throw new \Exception('برآورد اولیه (Pb) باید مقدار مثبت داشته باشد. لطفاً ابتدا بخش‌های برآورد اولیه را ثبت کنید.');
        }

        // دریافت شاخص‌ها
        $indices = Index::getByTenderId($tenderId);
        if (empty($indices)) {
            // ارسال خطا E001
            $this->sendAlert('E001', $tenderId, ['indices_count' => 0]);
            throw new \Exception('هیچ شاخصی برای این مناقصه ثبت نشده است. لطفاً ابتدا شاخص‌های مورد نیاز را ثبت کنید.');
        }

        $indexMap = $this->mapIndices($indices);
        
        // لاگ برای دیباگ
        \Log::info('Po calculation - Index mapping', [
            'tender_id' => $tenderId,
            'indices_count' => count($indices),
            'indices' => $indices,
            'index_map' => $indexMap,
        ]);

        $method = $tender->po_method ?? '1';
        $isAdjustable = $tender->is_adjustable ?? false;
        $T_gamma = floatval($tender->tgamma ?? 1.0);
        $T_beta = floatval($tender->tbeta ?? 0.5);
        $delta = floatval($tender->delta ?? 0);

        // بررسی ناسازگاری شاخص‌ها برای روش 2 و 3
        if ($method === '2' || $method === '3') {
            $inconsistencyCheck = $this->alertService->checkIndexInconsistency($tenderId, $method);
            if ($inconsistencyCheck) {
                // هشدار ثبت شد، اما ادامه می‌دهیم
            }
        }

        try {
            $result = match ($method) {
                '1' => $this->calculateMethod1($Pb, $isAdjustable, $T_gamma, $T_beta, $delta, $indexMap),
                '2' => $this->calculateMethod2($Pb, $isAdjustable, $indexMap),
                '3' => $this->calculateMethod3($Pb, $isAdjustable, $indexMap),
                default => throw new \Exception('روش محاسبه نامعتبر است'),
            };

            // بررسی خطا در اعمال روش پیش‌بینی (برای روش 2 و 3)
            if ($method === '2' || $method === '3') {
                $forecastError = $this->alertService->checkForecastMethodError($tenderId, $method, [
                    'f_prime_2' => $result['F2_prime'] ?? null,
                    'f_prime_3' => $result['F2_prime'] ?? null,
                    'mean_changes' => $result['avgRatio'] ?? $result['avgDiff'] ?? null,
                ]);
                if ($forecastError) {
                    // خطا ثبت شد و فرآیند متوقف می‌شود
                    throw new \Exception('خطا در اعمال روش پیش‌بینی');
                }
            }

            // بررسی محدوده ناهماهنگ Po نسبت به Pb
            $po = $result['Po'] ?? 0;
            $unreasonableCheck = $this->alertService->checkUnreasonablePoRange($tenderId, $Pb, $po);
            if ($unreasonableCheck) {
                // هشدار ثبت شد
            }

            // بررسی صحت beta/gamma برای کارهای دارای تعدیل/فاقد تعدیل
            $beta = $result['beta'] ?? null;
            $gamma = $result['gamma'] ?? null;
            $validationService = new \App\Services\DataValidationService();
            $betaGammaValidation = $validationService->validateBetaGamma($beta, $gamma, $isAdjustable);
            
            if (!$betaGammaValidation['valid']) {
                // ارسال خطا برای ضریب نامعتبر
                $this->sendAlert('E011', $tenderId, [
                    'method' => $method,
                    'is_adjustable' => $isAdjustable,
                    'beta' => $beta,
                    'gamma' => $gamma,
                    'errors' => $betaGammaValidation['errors'],
                ]);
                throw new \Exception('ضریب محاسبه شده نامعتبر است: ' . implode(', ', $betaGammaValidation['errors']));
            }
            
            if (!empty($betaGammaValidation['warnings'])) {
                // ارسال هشدار برای ضریب خارج از محدوده منطقی
                $this->sendAlert('W011', $tenderId, [
                    'method' => $method,
                    'is_adjustable' => $isAdjustable,
                    'beta' => $beta,
                    'gamma' => $gamma,
                    'warnings' => $betaGammaValidation['warnings'],
                ]);
            }
        } catch (\Exception $e) {
            // لاگ کردن جزئیات برای دیباگ
            \Log::error('Po calculation error', [
                'tender_id' => $tenderId,
                'method' => $method,
                'indices_count' => count($indices),
                'index_map' => $indexMap,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $result['Pb'] = $Pb;
        $result['method'] = $method;

        // ثبت در Audit Trail
        $auditTrailService = new \App\Services\AuditTrailService();
        $auditTrailService->logCalculation(
            $tenderId,
            'PO_CALCULATION',
            [
                'pb' => $Pb,
                'method' => $method,
                'is_adjustable' => $isAdjustable,
                'indices' => array_keys($indexMap),
            ],
            [
                'po' => $result['Po'],
                'beta' => $result['beta'] ?? null,
                'gamma' => $result['gamma'] ?? null,
            ],
            AlertNotificationService::STAGE_PO_CALCULATION,
            $result['formula'] ?? null
        );

        // ثبت ورودی Po با استفاده از InputTrackingService
        $inputTrackingService = new \App\Services\InputTrackingService();
        $inputTrackingService->trackPo($tenderId, $result['Po'], [
            'method' => $method,
            'beta' => $result['beta'] ?? null,
            'gamma' => $result['gamma'] ?? null,
            'formula' => $result['formula'] ?? null,
        ]);

        // ثبت β و γ اگر موجود باشند
        if (isset($result['beta'])) {
            $inputTrackingService->trackBeta($tenderId, $result['beta'], [
                'method' => $method,
                'po' => $result['Po'],
            ]);
        }
        if (isset($result['gamma'])) {
            $inputTrackingService->trackGamma($tenderId, $result['gamma'], [
                'method' => $method,
                'po' => $result['Po'],
            ]);
        }

        return $result;
    }

    /**
     * روش اول: محاسبه با شاخص‌های تورم
     */
    private function calculateMethod1(float $Pb, bool $isAdjustable, float $T_gamma, float $T_beta, float $delta, array $indexMap): array
    {
        $I1 = $indexMap['I1'] ?? 0;
        $I2 = $indexMap['I2'] ?? 0;
        $I3 = $indexMap['I3'] ?? 0;
        $r1 = $indexMap['r1'] ?? 0;
        $r2 = $indexMap['r2'] ?? 0;

        if ($I1 <= 0) {
            throw new \Exception('شاخص I₁ (I1) باید مقدار معتبری داشته باشد. لطفاً شاخص I1 را ثبت کنید.');
        }
        
        // بررسی شاخص‌های مورد نیاز بر اساس شرایط
        if (!$isAdjustable && $I2 <= 0) {
            throw new \Exception('شاخص I₂ (I2) باید مقدار معتبری داشته باشد. لطفاً شاخص I2 را ثبت کنید.');
        }
        
        if ($I2 <= 0 && $I3 <= 0) {
            throw new \Exception('حداقل یکی از شاخص‌های I₂ (I2) یا I₃ (I3) باید مقدار معتبری داشته باشد.');
        }

        $I2_equals_I3 = abs($I2 - $I3) < 0.0001;

        $beta = null;
        $gamma = null;
        $Po = 0;
        $formula = '';
        $details = '';

        // تلاش برای استفاده از فرمول‌های دیتابیس
        $dbFormula = null;
        $formulaName = null;
        
        if ($isAdjustable) {
            // کار دارای تعدیل
            if ($I2_equals_I3) {
                $formulaName = 'Po_روش1_تعدیل_I2_برابر_I3';
            } else {
                $formulaName = 'Po_روش1_تعدیل_I2_مخالف_I3';
            }
        } else {
            // کار فاقد تعدیل
            if ($I2_equals_I3) {
                $formulaName = 'Po_روش1_فاقد_تعدیل_I2_برابر_I3';
            } else {
                $formulaName = 'Po_روش1_فاقد_تعدیل_I2_مخالف_I3';
            }
        }
        
        // تلاش برای دریافت فرمول از دیتابیس
        $dbFormula = FormulaService::getFormulaModel($formulaName);
        
        if ($dbFormula && $dbFormula->is_active) {
            // استفاده از فرمول دیتابیس
            $variables = [
                'Pb' => $Pb,
                'I1' => $I1,
                'I2' => $I2,
                'I3' => $I3,
                'r1' => $r1,
                'r2' => $r2,
                'Tβ' => $T_beta,
                'T_γ' => $T_gamma,
            ];
            
            try {
                $Po = $dbFormula->evaluate($variables);
                $formula = $dbFormula->formula;
                
                // محاسبه beta/gamma برای نمایش و return
                if ($isAdjustable) {
                    if ($I2_equals_I3) {
                        $beta = $I2 / $I1;
                        $details = "کار دارای تعدیل - I₂ = I₃\nβ = I₂/I₁ = {$I2}/{$I1} = " . number_format($beta, 8);
                    } else {
                        $term = pow(1 + $r2, $T_beta);
                        $beta = ($I3 / $I1) * $term;
                        $details = "کار دارای تعدیل - I₂ ≠ I₃\nβ = I₃/I₁ × (1+r₂)^(T_β) = " . number_format($beta, 8);
                    }
                    $gamma = null; // برای کار دارای تعدیل، gamma null است
                } else {
                    if ($I2_equals_I3) {
                        $term = pow(1 + $r1, $T_gamma);
                        $gamma = ($I2 / $I1) * $term;
                        $details = "کار فاقد تعدیل - I₂ = I₃\nγ = (I₂/I₁) × (1+r₁)^(T_γ) = " . number_format($gamma, 8);
                    } else {
                        $term = pow(1 + $r2, $T_gamma);
                        $gamma = ($I3 / $I1) * $term;
                        $details = "کار فاقد تعدیل - I₂ ≠ I₃\nγ = I₃/I₁ × (1+r₂)^(T_γ) = " . number_format($gamma, 8);
                    }
                    $beta = null; // برای کار فاقد تعدیل، beta null است
                }
            } catch (\Exception $e) {
                \Log::warning("Error evaluating Po formula from database: " . $e->getMessage());
                $dbFormula = null; // Fallback to hard-coded formula
            }
        }
        
        // Fallback to hard-coded formula if database formula not available
        if (!$dbFormula || !$dbFormula->is_active) {
            if ($isAdjustable) {
                // کار دارای تعدیل
                if ($I2_equals_I3) {
                    $beta = $I2 / $I1;
                    $Po = $Pb * $beta;
                    $formula = 'Pₒ = P_b × β = P_b × (I₂/I₁)';
                    $details = "کار دارای تعدیل - I₂ = I₃\nβ = I₂/I₁ = {$I2}/{$I1} = " . number_format($beta, 8);
                } else {
                    $term = pow(1 + $r2, $T_beta);
                    $beta = ($I3 / $I1) * $term;
                    $Po = $Pb * $beta;
                    $formula = 'Pₒ = P_b × β = P_b × [I₃/I₁ × (1+r₂)^(T_β)]';
                    $details = "کار دارای تعدیل - I₂ ≠ I₃\nβ = I₃/I₁ × (1+r₂)^(T_β) = " . number_format($beta, 8);
                }
            } else {
                // کار فاقد تعدیل
                if ($I2_equals_I3) {
                    $term = pow(1 + $r1, $T_gamma);
                    $gamma = ($I2 / $I1) * $term;
                    $Po = $Pb * $gamma;
                    $formula = 'Pₒ = P_b × γ = P_b × [(I₂/I₁) × (1+r₁)^(T_γ)]';
                    $details = "کار فاقد تعدیل - I₂ = I₃\nγ = (I₂/I₁) × (1+r₁)^(T_γ) = " . number_format($gamma, 8);
                } else {
                    $term = pow(1 + $r2, $T_gamma);
                    $gamma = ($I3 / $I1) * $term;
                    $Po = $Pb * $gamma;
                    $formula = 'Pₒ = P_b × γ = P_b × [I₃/I₁ × (1+r₂)^(T_γ)]';
                    $details = "کار فاقد تعدیل - I₂ ≠ I₃\nγ = I₃/I₁ × (1+r₂)^(T_γ) = " . number_format($gamma, 8);
                }
            }
        }

        return [
            'Po' => round($Po, 2),
            'beta' => $beta,
            'gamma' => $gamma,
            'formula' => $formula,
            'details' => $details,
        ];
    }

    /**
     * روش دوم: میانگین درصد تغییرات
     */
    private function calculateMethod2(float $Pb, bool $isAdjustable, array $indexMap): array
    {
        $F1 = $indexMap['F1'] ?? 0;
        $F2 = $indexMap['F2'] ?? 0; // شاخص اعلام شده
        
        // لاگ برای دیباگ
        \Log::info('Method2 - F2 check', [
            'F2_from_map' => $F2,
            'indexMap_F2' => $indexMap['F2'] ?? 'NOT_SET',
            'indexMap_keys' => array_keys($indexMap),
            'full_indexMap' => $indexMap,
        ]);
        
        $z = $indexMap['z'] ?? 0;

        if ($F1 <= 0) {
            throw new \Exception('شاخص F₁ (F1) باید مقدار معتبری داشته باشد. لطفاً شاخص F1 را ثبت کنید.');
        }

        $f_series = [];
        for ($i = 1; $i <= 9; $i++) {
            $f_series[$i] = $indexMap["f{$i}"] ?? 0;
        }
        
        // بررسی اینکه آیا حداقل f1 و f9 وجود دارند (برای محاسبه F'2)
        if ($F2 <= 0 && ($f_series[1] <= 0 || $f_series[9] <= 0)) {
            throw new \Exception('برای محاسبه F\'₂، شاخص F₂ (F2) یا حداقل شاخص‌های f₁ (f1) و f₉ (f9) باید مقدار معتبری داشته باشند.');
        }

        $beta = null;
        $gamma = null;
        $F2_prime = null;
        $formula = '';
        $details = '';
        $Po = 0;

        // تلاش برای استفاده از فرمول‌های دیتابیس
        $dbFormula = null;
        $formulaName = null;
        $F2_declared = $F2 > 0;
        
        if ($F2_declared) {
            // شاخص اعلام شده است
            if ($isAdjustable) {
                $formulaName = 'Po_روش2_تعدیل_شاخص_اعلام_شده';
            } else {
                $formulaName = 'Po_روش2_فاقد_تعدیل_شاخص_اعلام_شده';
            }
        } else {
            // شاخص اعلام نشده است
            if ($isAdjustable) {
                $formulaName = 'Po_روش2_تعدیل_شاخص_اعلام_نشده';
            } else {
                $formulaName = 'Po_روش2_فاقد_تعدیل_شاخص_اعلام_نشده';
            }
        }
        
        // تلاش برای دریافت فرمول از دیتابیس
        $dbFormula = FormulaService::getFormulaModel($formulaName);
        
        if ($F2 > 0) {
            // شاخص اعلام شده است
            if ($dbFormula && $dbFormula->is_active) {
                // استفاده از فرمول دیتابیس
                $variables = [
                    'Pb' => $Pb,
                    'F1' => $F1,
                    'F2' => $F2,
                ];
                
                try {
                    $Po = $dbFormula->evaluate($variables);
                    $formula = $dbFormula->formula;
                    
                    if ($isAdjustable) {
                        $beta = $F2 / $F1;
                        $details = "کار دارای تعدیل - شاخص اعلام شده\nβ = F₂/F₁ = {$F2}/{$F1} = " . number_format($beta, 8);
                    } else {
                        $gamma = $F2 / $F1;
                        $details = "کار فاقد تعدیل - شاخص اعلام شده\nγ = F₂/F₁ = {$F2}/{$F1} = " . number_format($gamma, 8);
                    }
                    $F2_prime = $F2;
                } catch (\Exception $e) {
                    \Log::warning("Error evaluating Po method2 formula from database: " . $e->getMessage());
                    $dbFormula = null; // Fallback to hard-coded formula
                }
            }
            
            // Fallback to hard-coded formula
            if (!$dbFormula || !$dbFormula->is_active) {
                if ($isAdjustable) {
                    $beta = $F2 / $F1;
                    $Po = $Pb * $beta;
                    $formula = 'Pₒ = P_b × β = P_b × (F₂/F₁)';
                    $details = "کار دارای تعدیل - شاخص اعلام شده\nβ = F₂/F₁ = {$F2}/{$F1} = " . number_format($beta, 8);
                } else {
                    $gamma = $F2 / $F1;
                    $Po = $Pb * $gamma;
                    $formula = 'Pₒ = P_b × γ = P_b × (F₂/F₁)';
                    $details = "کار فاقد تعدیل - شاخص اعلام شده\nγ = F₂/F₁ = {$F2}/{$F1} = " . number_format($gamma, 8);
                }
                $F2_prime = $F2;
            }
        } else {
            // محاسبه F'₂ با میانگین نسبت‌ها
            $totalRatio = 0;
            $ratioCount = 0;
            for ($i = 2; $i <= 9; $i++) {
                if ($f_series[$i - 1] > 0 && $f_series[$i] > 0) {
                    $totalRatio += $f_series[$i] / $f_series[$i - 1];
                    $ratioCount++;
                }
            }

            if ($ratioCount === 0) {
                throw new \Exception('نمی‌توان میانگین نسبت‌ها را محاسبه کرد');
            }

            $avgRatio = $totalRatio / $ratioCount;
            $f9 = $f_series[9];

            if ($f9 <= 0) {
                throw new \Exception('f9 باید مقدار معتبری داشته باشد');
            }

            $F2_prime = $f9 * pow($avgRatio, $z);
            $beta = $F2_prime / $F1;
            $gamma = $F2_prime / $F1;
            
            // اعتبارسنجی β و γ
            $validationService = new \App\Services\DataValidationService();
            $betaGammaValidation = $validationService->validateBetaGamma($beta, $gamma, $isAdjustable);
            if (!$betaGammaValidation['valid']) {
                throw new \Exception('ضریب β یا γ نامعتبر است: ' . implode(', ', $betaGammaValidation['errors']));
            }
            
            // استفاده از فرمول دیتابیس برای شاخص اعلام نشده
            if ($dbFormula && $dbFormula->is_active) {
                $variables = [
                    'Pb' => $Pb,
                    'F1' => $F1,
                    'F2_prime' => $F2_prime,
                ];
                
                try {
                    $Po = $dbFormula->evaluate($variables);
                    $formula = $dbFormula->formula;
                } catch (\Exception $e) {
                    \Log::warning("Error evaluating Po method2 formula from database: " . $e->getMessage());
                    $dbFormula = null; // Fallback to hard-coded formula
                }
            }
            
            // Fallback to hard-coded formula
            if (!$dbFormula || !$dbFormula->is_active) {
                $Po = $Pb * ($isAdjustable ? $beta : $gamma);
                $formula = $isAdjustable ? 'Pₒ = P_b × β = P_b × (F\'₂/F₁)' : 'Pₒ = P_b × γ = P_b × (F\'₃/F₁)';
            }
            
            $details = "روش دوم (میانگین درصد تغییرات) - شاخص اعلام نشده\n";
            $details .= "میانگین نسبت‌ها (a) = " . number_format($avgRatio, 8) . "\n";
            $details .= "F'₂ = f₉ × a^z = {$f9} × " . number_format($avgRatio, 8) . "^{$z} = " . number_format($F2_prime, 8) . "\n";
            $details .= "β = " . number_format($beta, 8) . ", γ = " . number_format($gamma, 8);
        }

        return [
            'Po' => round($Po, 2),
            'beta' => $beta,
            'gamma' => $gamma,
            'F2_prime' => $F2_prime,
            'formula' => $formula,
            'details' => $details,
        ];
    }

    /**
     * روش سوم: میانگین تفاضل عددی
     */
    private function calculateMethod3(float $Pb, bool $isAdjustable, array $indexMap): array
    {
        $F1 = $indexMap['F1'] ?? 0;
        $F2 = $indexMap['F2'] ?? 0;
        $z = $indexMap['z'] ?? 0;

        if ($F1 <= 0) {
            throw new \Exception('شاخص F₁ (F1) باید مقدار معتبری داشته باشد. لطفاً شاخص F1 را ثبت کنید.');
        }

        $f_series = [];
        for ($i = 1; $i <= 9; $i++) {
            $f_series[$i] = $indexMap["f{$i}"] ?? 0;
        }
        
        // بررسی اینکه آیا حداقل f4, f5, f8, f9 وجود دارند (برای محاسبه میانگین تفاضل)
        if ($F2 <= 0) {
            $hasRequiredIndices = false;
            // بررسی وجود حداقل یکی از جفت‌های مورد نیاز
            if (($f_series[4] > 0 && $f_series[8] > 0) || 
                ($f_series[5] > 0 && $f_series[9] > 0) ||
                ($f_series[6] > 0 && $f_series[7] > 0) ||
                ($f_series[7] > 0 && $f_series[8] > 0) ||
                ($f_series[8] > 0 && $f_series[9] > 0)) {
                $hasRequiredIndices = true;
            }
            
            if (!$hasRequiredIndices) {
                throw new \Exception('برای محاسبه میانگین تفاضل، شاخص F₂ (F2) یا حداقل چند شاخص از سری f₄ تا f₉ باید مقدار معتبری داشته باشند.');
            }
        }

        $beta = null;
        $gamma = null;
        $F2_prime = null;
        $formula = '';
        $details = '';
        $Po = 0;

        // تلاش برای استفاده از فرمول‌های دیتابیس
        $dbFormula = null;
        $formulaName = null;
        $F2_declared = $F2 > 0;
        
        if ($F2_declared) {
            // شاخص اعلام شده است
            if ($isAdjustable) {
                $formulaName = 'Po_روش3_تعدیل_شاخص_اعلام_شده';
            } else {
                $formulaName = 'Po_روش3_فاقد_تعدیل_شاخص_اعلام_شده';
            }
        } else {
            // شاخص اعلام نشده است
            if ($isAdjustable) {
                $formulaName = 'Po_روش3_تعدیل_شاخص_اعلام_نشده';
            } else {
                $formulaName = 'Po_روش3_فاقد_تعدیل_شاخص_اعلام_نشده';
            }
        }
        
        // تلاش برای دریافت فرمول از دیتابیس
        $dbFormula = FormulaService::getFormulaModel($formulaName);
        
        if ($F2 > 0) {
            // شاخص اعلام شده است
            if ($dbFormula && $dbFormula->is_active) {
                // استفاده از فرمول دیتابیس
                $variables = [
                    'Pb' => $Pb,
                    'F1' => $F1,
                    'F2' => $F2,
                ];
                
                try {
                    $Po = $dbFormula->evaluate($variables);
                    $formula = $dbFormula->formula;
                    
                    if ($isAdjustable) {
                        $beta = $F2 / $F1;
                        $details = "کار دارای تعدیل - شاخص اعلام شده\nβ = F₂/F₁ = {$F2}/{$F1} = " . number_format($beta, 8);
                    } else {
                        $gamma = $F2 / $F1;
                        $details = "کار فاقد تعدیل - شاخص اعلام شده\nγ = F₂/F₁ = {$F2}/{$F1} = " . number_format($gamma, 8);
                    }
                    $F2_prime = $F2;
                } catch (\Exception $e) {
                    \Log::warning("Error evaluating Po method3 formula from database: " . $e->getMessage());
                    $dbFormula = null; // Fallback to hard-coded formula
                }
            }
            
            // Fallback to hard-coded formula
            if (!$dbFormula || !$dbFormula->is_active) {
                if ($isAdjustable) {
                    $beta = $F2 / $F1;
                    $Po = $Pb * $beta;
                    $formula = 'Pₒ = P_b × β = P_b × (F₂/F₁)';
                    $details = "کار دارای تعدیل - شاخص اعلام شده\nβ = F₂/F₁ = {$F2}/{$F1} = " . number_format($beta, 8);
                } else {
                    $gamma = $F2 / $F1;
                    $Po = $Pb * $gamma;
                    $formula = 'Pₒ = P_b × γ = P_b × (F₂/F₁)';
                    $details = "کار فاقد تعدیل - شاخص اعلام شده\nγ = F₂/F₁ = {$F2}/{$F1} = " . number_format($gamma, 8);
                }
                $F2_prime = $F2;
            }
        } else {
            // محاسبه با میانگین تفاضل
            $seasonalChanges = 0;
            $seasonalCount = 0;
            for ($i = 6; $i <= 9; $i++) {
                if ($f_series[$i] > 0 && $f_series[$i - 1] > 0) {
                    $seasonalChanges += ($f_series[$i] - $f_series[$i - 1]);
                    $seasonalCount++;
                }
            }

            $yearlyChanges = 0;
            $yearlyCount = 0;
            if ($f_series[9] > 0 && $f_series[5] > 0) {
                $yearlyChanges += ($f_series[9] - $f_series[5]);
                $yearlyCount++;
            }
            if ($f_series[8] > 0 && $f_series[4] > 0) {
                $yearlyChanges += ($f_series[8] - $f_series[4]);
                $yearlyCount++;
            }

            $totalChanges = $seasonalChanges + $yearlyChanges;
            $totalCount = $seasonalCount + $yearlyCount;

            if ($totalCount === 0) {
                throw new \Exception('نمی‌توان میانگین تفاضل را محاسبه کرد');
            }

            $avgDiff = $totalChanges / $totalCount;
            $f9 = $f_series[9];

            if ($f9 <= 0) {
                throw new \Exception('f9 باید مقدار معتبری داشته باشد');
            }

            $F2_prime = $f9 + ($avgDiff * $z);
            $beta = $F2_prime / $F1;
            $gamma = $F2_prime / $F1;
            
            // استفاده از فرمول دیتابیس برای شاخص اعلام نشده
            if ($dbFormula && $dbFormula->is_active) {
                $variables = [
                    'Pb' => $Pb,
                    'F1' => $F1,
                    'F2_prime' => $F2_prime,
                ];
                
                try {
                    $Po = $dbFormula->evaluate($variables);
                    $formula = $dbFormula->formula;
                } catch (\Exception $e) {
                    \Log::warning("Error evaluating Po method3 formula from database: " . $e->getMessage());
                    $dbFormula = null; // Fallback to hard-coded formula
                }
            }
            
            // Fallback to hard-coded formula
            if (!$dbFormula || !$dbFormula->is_active) {
                $Po = $Pb * ($isAdjustable ? $beta : $gamma);
                $formula = $isAdjustable ? 'Pₒ = P_b × β = P_b × (F\'₂/F₁)' : 'Pₒ = P_b × γ = P_b × (F\'₃/F₁)';
            }
            
            $details = "روش سوم (میانگین شش تفاضل) - شاخص اعلام نشده\n";
            $details .= "میانگین تفاضل (b) = " . number_format($avgDiff, 8) . "\n";
            $details .= "F'₂ = f₉ + (b × z) = {$f9} + (" . number_format($avgDiff, 8) . " × {$z}) = " . number_format($F2_prime, 8) . "\n";
            $details .= "β = " . number_format($beta, 8) . ", γ = " . number_format($gamma, 8);
        }

        return [
            'Po' => round($Po, 2),
            'beta' => $beta,
            'gamma' => $gamma,
            'F2_prime' => $F2_prime,
            'formula' => $formula,
            'details' => $details,
        ];
    }

    /**
     * تبدیل آرایه شاخص‌ها به map
     */
    private function mapIndices(array $indices): array
    {
        $map = [];
        foreach ($indices as $index) {
            $type = trim($index['type'] ?? '');
            $typeLower = strtolower($type);
            $value = floatval($index['value'] ?? 0);
            
            // نرمال‌سازی نام شاخص‌ها - پشتیبانی از هر دو حالت uppercase و lowercase
            if (in_array($typeLower, ['i1', 'i_1'])) {
                $map['I1'] = $value;
            } elseif (in_array($typeLower, ['i2', 'i_2'])) {
                $map['I2'] = $value;
            } elseif (in_array($typeLower, ['i3', 'i_3'])) {
                $map['I3'] = $value;
            } elseif (in_array($typeLower, ['r1', 'r_1'])) {
                $map['r1'] = $value;
            } elseif (in_array($typeLower, ['r2', 'r_2'])) {
                $map['r2'] = $value;
            } elseif (in_array($typeLower, ['f1', 'f_1'])) {
                $map['F1'] = $value;
                $map['f1'] = $value;
            } elseif ($type === 'F2' || $type === 'F_2') {
                // F2 (uppercase) شاخص اعلام شده است
                $map['F2'] = $value;
            } elseif (in_array($typeLower, ['f2', 'f_2'])) {
                // f2 (lowercase) فقط برای سری تاریخی است، نه F2 (uppercase) که شاخص اعلام شده است
                // پس فقط f2 را تنظیم می‌کنیم، نه F2
                $map['f2'] = $value;
            } elseif (in_array($typeLower, ['f3', 'f_3'])) {
                $map['f3'] = $value;
            } elseif (in_array($typeLower, ['f4', 'f_4'])) {
                $map['f4'] = $value;
            } elseif (in_array($typeLower, ['f5', 'f_5'])) {
                $map['f5'] = $value;
            } elseif (in_array($typeLower, ['f6', 'f_6'])) {
                $map['f6'] = $value;
            } elseif (in_array($typeLower, ['f7', 'f_7'])) {
                $map['f7'] = $value;
            } elseif (in_array($typeLower, ['f8', 'f_8'])) {
                $map['f8'] = $value;
            } elseif (in_array($typeLower, ['f9', 'f_9'])) {
                $map['f9'] = $value;
            } elseif ($typeLower === 'z') {
                $map['z'] = $value;
            }
        }
        return $map;
    }
}


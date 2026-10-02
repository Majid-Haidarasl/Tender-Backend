<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Bidder;
use App\Models\Estimate;
use App\Models\EvaluationResult;
use App\Models\DecisionHistory;
use Illuminate\Support\Facades\DB;

/**
 * سرویس ارزیابی مالی مناقصه‌گران
 * مطابق با دستورالعمل ارزیابی مالی وزارت نفت
 */
class EvaluationService
{
    private $alertService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
    }

    /**
     * ارسال هشدار/خطا/اطلاع
     */
    private function sendAlert(string $messageId, string $tenderId, array $context = []): void
    {
        try {
            $messageConfig = $this->alertService->getMessage($messageId);
            if ($messageConfig) {
                match ($messageConfig['type']) {
                    AlertNotificationService::TYPE_ERROR => $this->alertService->sendError($messageId, $tenderId, $context),
                    AlertNotificationService::TYPE_WARNING => $this->alertService->sendWarning($messageId, $tenderId, $context),
                    AlertNotificationService::TYPE_INFO => $this->alertService->sendInfo($messageId, $tenderId, $context),
                    default => null,
                };
            }
        } catch (\Exception $e) {
            \Log::warning("Could not send alert: " . $e->getMessage());
        }
    }

    /**
     * انجام ارزیابی کامل برای یک مناقصه
     * مطابق با دستورالعمل ارزیابی مالی وزارت نفت
     * اگر ورودی‌ها تغییر نکرده‌اند و نتایج قبلی وجود دارد، نتایج قبلی را برمی‌گرداند
     */
    public function evaluate(string $tenderId, bool $forceRecalculate = false): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            throw new \Exception('مناقصه یافت نشد');
        }

        // بررسی cache - اگر ورودی‌ها تغییر نکرده‌اند و نتایج قبلی وجود دارد
        if (!$forceRecalculate) {
            $cacheService = new \App\Services\CalculationCacheService();
            $hasInputsChanged = $cacheService->hasEvaluationInputsChanged($tenderId);
            
            $hasEvaluationCalculatedAt = \Illuminate\Support\Facades\Schema::hasColumn('tenders', 'evaluation_calculated_at');
            if (!$hasInputsChanged && (!$hasEvaluationCalculatedAt || $tender->evaluation_calculated_at)) {
                // نتایج قبلی معتبر است - برگرداندن نتایج قبلی
                \Log::info('Evaluation calculation cache hit', ['tender_id' => $tenderId]);
                return $this->getResults($tenderId);
            }
        }

        // اعتبارسنجی جامع قبل از ارزیابی
        $comprehensiveValidation = new \App\Services\ComprehensiveValidationFramework();
        if (!$comprehensiveValidation->validateBeforeEvaluation($tenderId)) {
            $report = $comprehensiveValidation->getValidationReport($tenderId);
            $errors = implode('; ', $report['blocking_errors']);
            throw new \Exception("اعتبارسنجی داده‌ها ناموفق بود: {$errors}");
        }

        $Po = floatval($tender->po ?? 0);
        if ($Po <= 0) {
            $this->sendAlert('E003', $tenderId, ['po' => $Po]);
            throw new \Exception('برآورد به‌هنگام (Po) باید محاسبه شده باشد');
        }

        // دریافت مناقصه‌گران
        $bidders = Bidder::getByTenderId($tenderId);
        if (empty($bidders)) {
            throw new \Exception('هیچ مناقصه‌گری ثبت نشده است');
        }

        // فیلتر مناقصه‌گران تایید شده
        $qualifiedBidders = array_filter($bidders, function ($b) {
            return ($b['is_qualified'] ?? true) !== false;
        });

        if (empty($qualifiedBidders)) {
            throw new \Exception('هیچ مناقصه‌گر تایید شده‌ای وجود ندارد');
        }

        // گام 0: محاسبه قیمت‌های تراز شده (P'_i) برای مناقصات دو مرحله‌ای
        // برای مناقصات تک مرحله‌ای: P'_i = P_i
        // برای مناقصات دو مرحله‌ای: P'_i = P_i × (A_max / A_i)
        if ($tender->is_two_stage) {
            // استفاده از سرویس اختصاصی تراز و امتیاز فنی-بازرگانی
            $technicalScoreService = new \App\Services\TechnicalCommercialScoreService();
            $adjustmentResult = $technicalScoreService->calculateAdjustedPrices(
                $tenderId,
                $qualifiedBidders,
                $tender->a_max ?? 100
            );
            
            if (!empty($adjustmentResult['errors'])) {
                throw new \Exception('خطا در محاسبه تراز: ' . implode(', ', $adjustmentResult['errors']));
            }
            
            $adjustedPrices = array_map(function ($adj) {
                return [
                    'bidder_id' => $adj['bidder_id'],
                    'bidder_name' => $adj['bidder_name'],
                    'original_price' => $adj['original_price'],
                    'adjusted_price' => $adj['adjusted_price'],
                    'technical_score' => $adj['technical_score'],
                ];
            }, $adjustmentResult['adjusted_prices']);
        } else {
            $adjustedPrices = $this->calculateAdjustedPrices($qualifiedBidders, $Po, false, 100);
        }

        // استخراج قیمت‌های تراز شده (P'_i) برای محاسبات آماری
        $P_prime_array = array_column($adjustedPrices, 'adjusted_price'); // P'_i
        $n = count($P_prime_array);

        // گام 1: بررسی مسیر ساده (ماده 6-1)
        // استفاده از فرمول‌های قابل تنظیم
        $formulaService = new \App\Services\FormulaService();
        $lowerSimpleFormula = $formulaService->getFormulaModel('حد_پایین_مسیر_ساده');
        $upperSimpleFormula = $formulaService->getFormulaModel('حد_بالای_مسیر_ساده');
        
        $lowerSimple = $lowerSimpleFormula 
            ? $formulaService->evaluateFormula($lowerSimpleFormula->formula, ['Po' => $Po])
            : 0.9 * $Po; // Fallback to default
        $upperSimple = $upperSimpleFormula 
            ? $formulaService->evaluateFormula($upperSimpleFormula->formula, ['Po' => $Po])
            : 1.1 * $Po; // Fallback to default
        $allInSimpleRange = true;
        foreach ($P_prime_array as $p) {
            if ($p < $lowerSimple || $p > $upperSimple) {
                $allInSimpleRange = false;
                break;
            }
        }
        
        // استفاده از فرمول شرط مسیر ساده
        $simpleRouteConditionFormula = $formulaService->getFormulaModel('شرط_مسیر_ساده');
        $simpleRouteCondition = true; // پیش‌فرض: true
        if ($simpleRouteConditionFormula) {
            // ارزیابی شرط: n < 4
            // چون فرمول شرط boolean است، باید آن را به صورت دستی بررسی کنیم
            $conditionFormula = $simpleRouteConditionFormula->formula;
            if (strpos($conditionFormula, 'n < 4') !== false || strpos($conditionFormula, 'n<4') !== false) {
                $simpleRouteCondition = $n < 4;
            } else {
                // اگر فرمول تغییر کرده، سعی می‌کنیم آن را ارزیابی کنیم
                try {
                    $conditionResult = $formulaService->evaluateFormula($conditionFormula, ['n' => $n]);
                    $simpleRouteCondition = $conditionResult > 0; // اگر نتیجه مثبت باشد، شرط برقرار است
                } catch (\Exception $e) {
                    // در صورت خطا، از پیش‌فرض استفاده می‌کنیم
                    $simpleRouteCondition = $n < 4;
                }
            }
        } else {
            $simpleRouteCondition = $n < 4; // Fallback to default
        }
        
        $useSimpleRoute = $simpleRouteCondition || $allInSimpleRange;

        // ارسال هشدار/اطلاع برای مسیر تصمیم‌گیری
        if ($n <= 2) {
            $this->sendAlert('W005', $tenderId, ['n' => $n]);
        }

        $P_Lower = 0;
        $P_Upper = 0;
        $m = 0; // میانگین بدون احتساب Po
        $m_o = 0;
        $s_o = 0;
        $m_prime_o = 0;
        $s_prime_o = 0;
        $path = '';
        $action = '';
        $finalResults = [];
        $lowerM62 = 0;
        $upperM62 = 0;
        $filterLower = 0;
        $filterUpper = 0;
        $primaryRangeLower = -1; // پیش‌فرض برای مسیر ساده
        $primaryRangeUpper = 1; // پیش‌فرض برای مسیر ساده

        if ($useSimpleRoute) {
            // مسیر ساده (ماده 6-1)
            $path = 'SIMPLE';
            
            // محاسبه P_Lower و P_Upper از محدوده ساده
            $P_Lower = $lowerSimple;
            $P_Upper = $upperSimple;
            
            // اطمینان از اینکه همه قیمت‌های adjusted_price در محدوده باشند
            // اگر قیمتی خارج از محدوده است، محدوده را گسترش می‌دهیم
            foreach ($P_prime_array as $price) {
                if ($price < $P_Lower) {
                    $P_Lower = $price;
                }
                if ($price > $P_Upper) {
                    $P_Upper = $price;
                }
            }
            
            // اطمینان از اینکه Po هم در محدوده باشد
            if ($Po < $P_Lower) {
                $P_Lower = $Po;
            }
            if ($Po > $P_Upper) {
                $P_Upper = $Po;
            }
            
            $m = $this->calculateMean($P_prime_array); // برای مسیر ساده، m همان m_o است
            $m_o = $m;
            $s_o = $this->calculateStdDev($P_prime_array, $m_o);
            // برای سازگاری، m62_lower و m62_upper را 0 قرار می‌دهیم (در مسیر ساده استفاده نمی‌شوند)
            $lowerM62 = 0;
            $upperM62 = 0;
        } else {
            // مسیر آماری (ماده 6-2 و 6-3)
            $path = 'STATISTICAL';

            // گام 2: بررسی شرط ماده 6-2
            $m = $this->calculateMean($P_prime_array); // میانگین بدون احتساب Po
            // استفاده از فرمول‌های قابل تنظیم
            $lowerM62Formula = $formulaService->getFormulaModel('حد_پایین_ماده_6_2');
            $upperM62Formula = $formulaService->getFormulaModel('حد_بالای_ماده_6_2');
            
            $lowerM62 = $lowerM62Formula 
                ? $formulaService->evaluateFormula($lowerM62Formula->formula, ['Po' => $Po])
                : 0.8 * $Po; // Fallback to default
            $upperM62 = $upperM62Formula 
                ? $formulaService->evaluateFormula($upperM62Formula->formula, ['Po' => $Po])
                : 1.35 * $Po; // Fallback to default

            if ($m < $lowerM62 || $m > $upperM62) {
                // ماده 6-2 احراز نشده - نیاز به بررسی مجدد توسط کمیته فنی-بازرگانی
                // طبق دستورالعمل، در این حالت باید P_b و P_o توسط کمیته بررسی شود
                // و تصمیم نهایی توسط کمیسیون مناقصه با در نظر گرفتن "صرفه و صلاح دستگاه" گرفته شود
                $action = 'REVIEW_REQUIRED_M62';
                
                // برای حالت REVIEW_REQUIRED_M62، محدوده را از قیمت‌های پیشنهادی محاسبه می‌کنیم
                // یا از دامنه ساده (0.9×Po تا 1.1×Po) استفاده می‌کنیم
                $qualifiedPrices = array_column($adjustedPrices, 'adjusted_price');
                if (!empty($qualifiedPrices)) {
                    $P_Lower = min($qualifiedPrices);
                    $P_Upper = max($qualifiedPrices);
                    // اطمینان از اینکه محدوده شامل Po هم باشد
                    $P_Lower = min($P_Lower, $Po * 0.9);
                    $P_Upper = max($P_Upper, $Po * 1.1);
                } else {
                    // اگر قیمتی نیست، از دامنه ساده استفاده کن
                    $P_Lower = $Po * 0.9;
                    $P_Upper = $Po * 1.1;
                }
                
                // ارسال هشدار W006
                $this->sendAlert('W006', $tenderId, [
                    'mean' => $m,
                    'lower_bound' => $lowerM62,
                    'upper_bound' => $upperM62,
                    'po' => $Po,
                ]);
                // آماده‌سازی نتایج برای حالت نیاز به بررسی
                $finalResults = $this->prepareFinalResultsFromAdjusted($adjustedPrices, $P_Lower, $P_Upper, $action);
                $this->saveResults($tenderId, $finalResults);
                Tender::setEvaluationCalculatedAt($tenderId);
                
                // محاسبه m_o و s_o برای نمایش
                $m_o = $this->calculateMean(array_merge($P_prime_array, [$Po]));
                $s_o = $this->calculateStdDev(array_merge($P_prime_array, [$Po]), $m_o);
                
                // ذخیره hash ورودی‌ها برای cache
                $cacheService = new \App\Services\CalculationCacheService();
                $cacheService->saveEvaluationInputHash($tenderId);
                return [
                    'tender_id' => $tenderId,
                    'Po' => $Po,
                    'mean' => $m,
                    'std_dev' => $s_o,
                    'ranges' => [
                        'm62_lower' => $lowerM62,
                        'm62_upper' => $upperM62,
                        'final_lower' => $P_Lower,
                        'final_upper' => $P_Upper,
                        'm_o' => $m_o,
                        's_o' => $s_o,
                    ],
                    'results' => $finalResults,
                    'action' => $action,
                    'path' => $path,
                    'calculation_log' => $this->generateCalculationLog($tenderId, $Po, $m, $s_o, [
                        'm62_lower' => $lowerM62,
                        'm62_upper' => $upperM62,
                    ], $finalResults, $action, $path),
                ];
            }

            // ماده 6-2 احراز شده - محاسبه m_o و s_o با احتساب Po (طبق دستورالعمل)
            // n = تعداد مناقصه‌گران + 1 (شامل P_o)
            $P_prime_array_with_Po = array_merge($P_prime_array, [$Po]);
            $m_o = $this->calculateMean($P_prime_array_with_Po);
            $s_o = $this->calculateStdDev($P_prime_array_with_Po, $m_o);
            
            // بررسی معتبر بودن mo و so
            if ($m_o <= 0 || $s_o <= 0 || is_nan($m_o) || is_nan($s_o)) {
                $this->sendAlert('W007', $tenderId, ['mo' => $m_o, 'so' => $s_o]);
                // اگر s_o صفر یا نامعتبر است، نمی‌توانیم تقسیم انجام دهیم
                if ($s_o <= 0 || is_nan($s_o)) {
                    throw new \Exception('انحراف معیار (s_o) نامعتبر است. نمی‌توان ارزیابی آماری انجام داد.');
                }
            }

            // بررسی ورودی‌های ناقص ترکیبی در مسیر تحلیل آماری
            $combinedErrors = $this->alertService->checkCombinedErrors($tenderId, [
                'pi_complete' => !empty($P_prime_array),
                'po' => $Po,
                'mo' => $m_o,
                'so' => $s_o,
                'n' => $n,
                'mean' => $m,
            ]);
            if (!empty($combinedErrors)) {
                // خطاهای ترکیبی ثبت شدند
            }

            // گام 3: نرمال‌سازی آماری (ماده 6-3)
            // محاسبه P''_i = (P'_i - m_o) / s_o برای هر مناقصه‌گر
            // و P''_o = (P_o - m_o) / s_o
            // بررسی مجدد s_o قبل از تقسیم برای جلوگیری از تقسیم بر صفر
            if ($s_o <= 0 || is_nan($s_o) || !is_finite($s_o)) {
                throw new \Exception('انحراف معیار (s_o) نامعتبر است. نمی‌توان ارزیابی آماری انجام داد.');
            }
            
            $statisticallyNormalized = [];
            foreach ($adjustedPrices as $bidder) {
                $P_double_prime_i = ($bidder['adjusted_price'] - $m_o) / $s_o;
                $statisticallyNormalized[] = [
                    'bidder_id' => $bidder['bidder_id'],
                    'bidder_name' => $bidder['bidder_name'],
                    'original_price' => $bidder['original_price'],
                    'adjusted_price' => $bidder['adjusted_price'], // P'_i
                    'statistically_normalized' => $P_double_prime_i, // P''_i
                    'technical_score' => $bidder['technical_score'],
                ];
            }
            $P_double_prime_o = ($Po - $m_o) / $s_o;

            // بررسی مقادیر نرمال‌شده خارج از حد منطقی
            $normalizedValues = array_column($statisticallyNormalized, 'statistically_normalized');
            $extremeCheck = $this->alertService->checkExtremeNormalizedValues($tenderId, $normalizedValues);
            if ($extremeCheck) {
                // هشدار ثبت شد
            }

            // دامنه اصلی: استفاده از فرمول‌های قابل تنظیم
            $primaryRangeLowerFormula = $formulaService->getFormulaModel('حد_پایین_دامنه_اصلی');
            $primaryRangeUpperFormula = $formulaService->getFormulaModel('حد_بالای_دامنه_اصلی');
            
            $primaryRangeLower = $primaryRangeLowerFormula
                ? $formulaService->evaluateFormula($primaryRangeLowerFormula->formula, [])
                : -1; // Fallback to default
            
            $primaryRangeUpper = $primaryRangeUpperFormula
                ? $formulaService->evaluateFormula($primaryRangeUpperFormula->formula, [])
                : 1; // Fallback to default
            
            // دامنه اصلی: -1 ≤ P''_i ≤ +1
            $inPrimaryRange = array_filter($statisticallyNormalized, function ($b) use ($primaryRangeLower, $primaryRangeUpper) {
                return $b['statistically_normalized'] >= $primaryRangeLower && $b['statistically_normalized'] <= $primaryRangeUpper;
            });

            // بررسی اینکه آیا P''_o در دامنه اصلی است
            $PoInPrimaryRange = ($P_double_prime_o >= $primaryRangeLower && $P_double_prime_o <= $primaryRangeUpper);

            // دامنه الحاقی (تبصره بند 6-3-3)
            // استفاده از فرمول‌های قابل تنظیم
            $annexPercentPrimaryFormula = $formulaService->getFormulaModel('درصد_دامنه_الحاقی_اصلی');
            $annexPercentSecondaryFormula = $formulaService->getFormulaModel('درصد_دامنه_الحاقی_فرعی');
            
            $annexRangePercent = $PoInPrimaryRange 
                ? ($annexPercentPrimaryFormula 
                    ? $formulaService->evaluateFormula($annexPercentPrimaryFormula->formula, [])
                    : 0.20) // Fallback to default
                : ($annexPercentSecondaryFormula 
                    ? $formulaService->evaluateFormula($annexPercentSecondaryFormula->formula, [])
                    : 0.10); // Fallback to default
            
            // استفاده از فرمول‌های حد دامنه الحاقی
            $annexLowerFormula = $formulaService->getFormulaModel('حد_پایین_دامنه_الحاقی');
            $annexUpperFormula = $formulaService->getFormulaModel('حد_بالای_دامنه_الحاقی');
            
            $annexLower = $annexLowerFormula
                ? $formulaService->evaluateFormula($annexLowerFormula->formula, ['Po' => $Po, 'annex_percent' => $annexRangePercent])
                : $Po * (1 - $annexRangePercent); // Fallback to default
            
            $annexUpper = $annexUpperFormula
                ? $formulaService->evaluateFormula($annexUpperFormula->formula, ['Po' => $Po, 'annex_percent' => $annexRangePercent])
                : $Po * (1 + $annexRangePercent); // Fallback to default

            // قیمت‌های خارج از دامنه اصلی که در دامنه الحاقی هستند
            $inAnnexRange = array_filter($statisticallyNormalized, function ($b) use ($annexLower, $annexUpper, $primaryRangeLower, $primaryRangeUpper) {
                $notInPrimary = ($b['statistically_normalized'] < $primaryRangeLower || $b['statistically_normalized'] > $primaryRangeUpper);
                $inAnnex = ($b['adjusted_price'] >= $annexLower && $b['adjusted_price'] <= $annexUpper);
                return $notInPrimary && $inAnnex;
            });

            // ترکیب دامنه اصلی و الحاقی
            $qualifiedBiddersData = array_merge($inPrimaryRange, $inAnnexRange);
            $qualifiedBiddersData = array_values($qualifiedBiddersData);

            if (empty($qualifiedBiddersData)) {
                // هیچ پیشنهادی در دامنه نیست
                $action = 'NO_VALID_BIDS';
                $P_Lower = $annexLower;
                $P_Upper = $annexUpper;
                $finalResults = $this->prepareFinalResultsFromNormalized($statisticallyNormalized, $P_Lower, $P_Upper, $action, $Po, $annexLower, $annexUpper, $PoInPrimaryRange, $primaryRangeLower, $primaryRangeUpper);
                $this->saveResults($tenderId, $finalResults);
                Tender::setEvaluationCalculatedAt($tenderId);
                
                // ذخیره hash ورودی‌ها برای cache
                $cacheService = new \App\Services\CalculationCacheService();
                $cacheService->saveEvaluationInputHash($tenderId);
                return [
                    'tender_id' => $tenderId,
                    'Po' => $Po,
                    'mean' => $m,
                    'std_dev' => $s_o,
                    'ranges' => [
                        'm62_lower' => $lowerM62,
                        'm62_upper' => $upperM62,
                        'primary_range_lower' => $primaryRangeLower ?? -1,
                        'primary_range_upper' => $primaryRangeUpper ?? 1,
                        'annex_range_percent' => $annexRangePercent * 100,
                        'annex_range_lower' => $annexLower,
                        'annex_range_upper' => $annexUpper,
                        'final_lower' => $P_Lower,
                        'final_upper' => $P_Upper,
                        'm_o' => $m_o,
                        's_o' => $s_o,
                        'P_double_prime_o' => $P_double_prime_o,
                        'Po_in_primary_range' => $PoInPrimaryRange,
                    ],
                    'results' => $finalResults,
                    'action' => $action,
                    'path' => $path,
                    'calculation_log' => $this->generateCalculationLog($tenderId, $Po, $m, $s_o, [
                        'm62_lower' => $lowerM62 ?? 0,
                        'm62_upper' => $upperM62 ?? 0,
                        'm_o' => $m_o,
                        's_o' => $s_o,
                        'P_double_prime_o' => $P_double_prime_o,
                        'Po_in_primary_range' => $PoInPrimaryRange,
                        'annex_range_percent' => $annexRangePercent * 100,
                    ], $finalResults, $action, $path),
                ];
            }

            // گام 4: تعیین محدوده نهایی
            // محدوده نهایی = دامنه اصلی + دامنه الحاقی
            // برای انتخاب برنده، از قیمت‌های تراز شده (P'_i) استفاده می‌شود
            $qualifiedPrices = array_column($qualifiedBiddersData, 'adjusted_price');
            
            if (!empty($qualifiedPrices)) {
                // P_Lower و P_Upper باید شامل تمام قیمت‌های در دامنه اصلی یا الحاقی باشد
                // ابتدا از کمترین و بیشترین قیمت در qualifiedPrices شروع می‌کنیم
                $P_Lower = min($qualifiedPrices);
                $P_Upper = max($qualifiedPrices);
                
                // اطمینان از اینکه محدوده شامل دامنه الحاقی کامل باشد
                // اما باید مطمئن شویم که همه قیمت‌های qualifiedPrices در محدوده باشند
                // پس P_Lower نباید بزرگتر از min($qualifiedPrices) باشد
                // و P_Upper نباید کوچکتر از max($qualifiedPrices) باشد
                // اما می‌توانیم محدوده را گسترش دهیم تا شامل دامنه الحاقی کامل شود
                // اما فقط اگر این باعث نشود که قیمت‌های qualifiedPrices خارج از محدوده شوند
                $minQualifiedPrice = min($qualifiedPrices);
                $maxQualifiedPrice = max($qualifiedPrices);
                
                // P_Lower باید شامل min($qualifiedPrices) باشد
                // اما می‌توانیم آن را به annexLower گسترش دهیم اگر annexLower کوچکتر باشد
                $P_Lower = min($minQualifiedPrice, $annexLower);
                
                // P_Upper باید شامل max($qualifiedPrices) باشد
                // اما می‌توانیم آن را به annexUpper گسترش دهیم اگر annexUpper بزرگتر باشد
                $P_Upper = max($maxQualifiedPrice, $annexUpper);
                
                // اگر P_o در دامنه الحاقی است، باید در محدوده نهایی باشد
                if ($Po >= $annexLower && $Po <= $annexUpper) {
                    $P_Lower = min($P_Lower, $Po);
                    $P_Upper = max($P_Upper, $Po);
                }
                
                // اطمینان نهایی: همه قیمت‌های qualifiedPrices باید در محدوده باشند
                // این باید همیشه درست باشد چون P_Lower <= min($qualifiedPrices) و P_Upper >= max($qualifiedPrices)
                // اما برای اطمینان، بررسی می‌کنیم
                foreach ($qualifiedPrices as $price) {
                    if ($price < $P_Lower || $price > $P_Upper) {
                        // اگر قیمتی خارج از محدوده است، محدوده را گسترش می‌دهیم
                        $P_Lower = min($P_Lower, $price);
                        $P_Upper = max($P_Upper, $price);
                    }
                }
            } else {
                // اگر هیچ قیمتی در دامنه نیست، از دامنه الحاقی استفاده کن
                $P_Lower = $annexLower;
                $P_Upper = $annexUpper;
            }
        }

        // گام 5: تعیین برندگان (ماده 7)
        // استفاده از قیمت‌های تراز شده (P'_i) برای انتخاب برنده
        if ($useSimpleRoute) {
            $finalResults = $this->prepareFinalResultsFromAdjusted($adjustedPrices, $P_Lower, $P_Upper, $action);
        } else {
            $finalResults = $this->prepareFinalResultsFromNormalized($statisticallyNormalized, $P_Lower, $P_Upper, $action, $Po, $annexLower, $annexUpper, $PoInPrimaryRange, $primaryRangeLower, $primaryRangeUpper);
        }
        $finalResults = $this->rankAndDetermineWinners($finalResults, $P_Lower, $P_Upper);

        $winner = array_filter($finalResults, function($r) {
            return $r['is_winner_first'] ?? false;
        });
        if (!empty($winner)) {
            $action = 'AWARD';
            // ارسال اطلاع I002
            $winnerData = reset($winner);
            $this->sendAlert('I002', $tenderId, [
                'winner_name' => $winnerData['bidder_name'] ?? '',
                'winner_price' => $winnerData['final_score'] ?? 0,
            ]);
        } else if ($action !== 'CANCELLED_M62') {
            $action = 'NO_VALID_BIDS';
        }

        // ذخیره نتایج
        $this->saveResults($tenderId, $finalResults);
        Tender::setEvaluationCalculatedAt($tenderId);
        
        // ذخیره hash ورودی‌ها برای cache
        $cacheService = new \App\Services\CalculationCacheService();
        $cacheService->saveEvaluationInputHash($tenderId);

            // ثبت ورودی‌های Pi در Audit Trail
            $inputTrackingService = new \App\Services\InputTrackingService();
            foreach ($adjustedPrices as $bidder) {
                $normalizedPrice = null;
                foreach ($statisticallyNormalized ?? [] as $normalized) {
                    if ($normalized['bidder_id'] === $bidder['bidder_id']) {
                        $normalizedPrice = $normalized['statistically_normalized'];
                        break;
                    }
                }
                $inputTrackingService->trackPi(
                    $tenderId,
                    $bidder['bidder_id'],
                    $bidder['original_price'],
                    $bidder['adjusted_price'],
                    $normalizedPrice
                );
            }

            // ثبت در Audit Trail
            $auditTrailService = new \App\Services\AuditTrailService();
            $auditTrailService->logDecision(
            $tenderId,
            DecisionHistory::DECISION_PATH_SELECTION,
            $path,
            AlertNotificationService::STAGE_DECISION_PATH,
            "مسیر {$path} انتخاب شد",
            [
                'n' => $n,
                'mean' => $useSimpleRoute ? $m_o : ($m ?? $m_o),
                'po' => $Po,
            ],
            [
                'm_o' => $m_o ?? null,
                's_o' => $s_o ?? null,
                'normalized_prices' => array_column($finalResults, 'normalized_price'),
            ],
            [
                'action' => $action,
                'winner' => $winner ? reset($winner) : null,
            ],
            [
                'n' => $n,
                'mean_in_range' => !$useSimpleRoute && isset($m) && $m >= (0.8 * $Po) && $m <= (1.35 * $Po),
            ],
            $useSimpleRoute ? [] : (isset($m) && ($m < 0.8 * $Po || $m > 1.35 * $Po) ? ['mean_out_of_range' => true] : []),
            null,
            null,
            $action === 'REVIEW_REQUIRED_M62'
        );

        // بررسی تطابق با دستورالعمل
        $complianceChecker = new \App\Services\ComplianceCheckerService();
        $compliance = $complianceChecker->checkCompliance($tenderId, [
            'path' => $path,
            'Po' => $Po,
            'mean' => $useSimpleRoute ? $m_o : ($m ?? $m_o),
            'std_dev' => $s_o,
            'results' => $finalResults,
            'ranges' => $useSimpleRoute ? [
                'm62_lower' => 0,
                'm62_upper' => 0,
                'final_lower' => $P_Lower,
                'final_upper' => $P_Upper,
            ] : [
                'm62_lower' => $lowerM62 ?? null,
                'm62_upper' => $upperM62 ?? null,
                'primary_range_lower' => $primaryRangeLower ?? -1,
                'primary_range_upper' => $primaryRangeUpper ?? 1,
                'annex_range_percent' => $annexRangePercent ?? null,
                'annex_range_lower' => $annexLower ?? null,
                'annex_range_upper' => $annexUpper ?? null,
                'Po_in_primary_range' => $PoInPrimaryRange ?? null,
            ],
        ]);

        if (!$compliance['compliant']) {
            \Log::error('Compliance violations detected', [
                'tender_id' => $tenderId,
                'violations' => $compliance['violations'],
            ]);
        }

        // تولید گزارش مرحله
        $stageReportService = new \App\Services\StageReportService();
        $stageReport = $stageReportService->generateStageReport($tenderId, AlertNotificationService::STAGE_WINNER_SELECTION, [
            'po' => $Po,
            'mean' => $useSimpleRoute ? $m_o : ($m ?? $m_o),
            'std_dev' => $s_o,
            'path' => $path,
            'action' => $action,
        ]);

        return [
            'tender_id' => $tenderId,
            'Po' => $Po,
            'mean' => $useSimpleRoute ? $m_o : ($m ?? $m_o),
            'std_dev' => $s_o,
                    'ranges' => $useSimpleRoute ? [
                        'm62_lower' => 0,
                        'm62_upper' => 0,
                        'final_lower' => $P_Lower,
                        'final_upper' => $P_Upper,
                        'm_o' => $m_o,
                        's_o' => $s_o,
                    ] : [
                        'm62_lower' => $lowerM62,
                        'm62_upper' => $upperM62,
                        'primary_range_lower' => $primaryRangeLower ?? -1,
                        'primary_range_upper' => $primaryRangeUpper ?? 1,
                        'annex_range_percent' => isset($annexRangePercent) ? $annexRangePercent * 100 : 0,
                        'annex_range_lower' => isset($annexLower) ? $annexLower : 0,
                        'annex_range_upper' => isset($annexUpper) ? $annexUpper : 0,
                        'final_lower' => $P_Lower,
                        'final_upper' => $P_Upper,
                        'm_o' => isset($m_o) ? $m_o : 0,
                        's_o' => isset($s_o) ? $s_o : 0,
                        'P_double_prime_o' => isset($P_double_prime_o) ? $P_double_prime_o : 0,
                        'Po_in_primary_range' => isset($PoInPrimaryRange) ? $PoInPrimaryRange : false,
                    ],
            'results' => $finalResults,
            'action' => $action,
            'path' => $path,
                    'calculation_log' => $this->generateCalculationLog($tenderId, $Po, $useSimpleRoute ? $m_o : ($m ?? $m_o), $s_o, $useSimpleRoute ? [
                        'm62_lower' => 0,
                        'm62_upper' => 0,
                        'final_lower' => $P_Lower,
                        'final_upper' => $P_Upper,
                        'm_o' => $m_o,
                        's_o' => $s_o,
                    ] : [
                        'm62_lower' => $lowerM62 ?? 0,
                        'm62_upper' => $upperM62 ?? 0,
                        'primary_range_lower' => $primaryRangeLower ?? -1,
                        'primary_range_upper' => $primaryRangeUpper ?? 1,
                        'annex_range_percent' => isset($annexRangePercent) ? $annexRangePercent * 100 : 0,
                        'annex_range_lower' => isset($annexLower) ? $annexLower : 0,
                        'annex_range_upper' => isset($annexUpper) ? $annexUpper : 0,
                        'final_lower' => $P_Lower,
                        'final_upper' => $P_Upper,
                        'm_o' => isset($m_o) ? $m_o : 0,
                        's_o' => isset($s_o) ? $s_o : 0,
                        'P_double_prime_o' => isset($P_double_prime_o) ? $P_double_prime_o : 0,
                        'Po_in_primary_range' => isset($PoInPrimaryRange) ? $PoInPrimaryRange : false,
                    ], $finalResults, $action, $path),
        ];
    }

    /**
     * محاسبه قیمت‌های تراز شده (P'_i)
     * برای مناقصه دو مرحله‌ای: P'_i = P_i × (A_max / A_i)
     * برای مناقصه تک مرحله‌ای: P'_i = P_i
     * 
     * این قیمت‌ها برای محاسبات آماری و انتخاب برنده استفاده می‌شوند
     */
    private function calculateAdjustedPrices(array $bidders, float $Po, bool $isTwoStage, float $aMax = 100): array
    {
        $adjusted = [];

        foreach ($bidders as $bidder) {
            $price = floatval($bidder['price'] ?? 0);
            if ($price <= 0) continue;

            $technicalScore = floatval($bidder['technical_score'] ?? $aMax);
            
            // محاسبه P'_i طبق فرمول صحیح
            if ($isTwoStage && $technicalScore > 0) {
                // P'_i = P_i × (A_max / A_i)
                $adjustedPrice = $price * ($aMax / $technicalScore);
            } else {
                // برای مناقصه تک مرحله‌ای: P'_i = P_i
                $adjustedPrice = $price;
            }

            $adjusted[] = [
                'bidder_id' => $bidder['id'],
                'bidder_name' => $bidder['name'],
                'original_price' => $price, // P_i
                'adjusted_price' => $adjustedPrice, // P'_i (قیمت تراز شده)
                'technical_score' => $technicalScore,
            ];
        }

        return $adjusted;
    }

    /**
     * محاسبه میانگین (m'_o)
     */
    private function calculateMean(array $values): float
    {
        if (empty($values)) return 0;
        return array_sum($values) / count($values);
    }

    /**
     * محاسبه انحراف معیار جمعیت (s'_o)
     */
    private function calculateStdDev(array $values, float $mean): float
    {
        if (empty($values)) return 0;

        $n = count($values);
        $sumSquareDiffs = 0;

        foreach ($values as $value) {
            $sumSquareDiffs += pow($value - $mean, 2);
        }

        return sqrt($sumSquareDiffs / $n);
    }

    /**
     * آماده‌سازی نتایج نهایی از قیمت‌های تراز شده (برای مسیر ساده)
     */
    private function prepareFinalResultsFromAdjusted(array $adjustedPrices, float $P_Lower, float $P_Upper, string $action): array
    {
        $results = [];
        foreach ($adjustedPrices as $bidder) {
            // بررسی اینکه آیا در محدوده نهایی است - بر اساس P_Lower و P_Upper
            // این منطق باید با منطق frontend هماهنگ باشد
            $adjustedPrice = floatval($bidder['adjusted_price'] ?? 0);
            
            // بررسی در محدوده بودن
            $inFinalRange = false;
            if ($action !== 'CANCELLED_M62') {
                // بررسی دقیق در محدوده بودن با استفاده از مقایسه float
                $inFinalRange = ($adjustedPrice >= $P_Lower && $adjustedPrice <= $P_Upper);
            }

            $results[] = [
                'bidder_id' => $bidder['bidder_id'],
                'bidder_name' => $bidder['bidder_name'],
                'original_price' => $bidder['original_price'], // P_i
                'normalized_price' => $adjustedPrice, // P'_i (برای نمایش)
                'adjusted_price' => $adjustedPrice, // P'_i
                'technical_score' => $bidder['technical_score'] ?? null,
                'final_score' => $adjustedPrice, // برای انتخاب برنده از P'_i استفاده می‌شود
                'in_final_range' => $inFinalRange,
                'is_winner' => false,
                'is_winner_first' => false,
                'is_winner_second' => false,
                'rank' => null,
            ];
        }
        return $results;
    }

    /**
     * آماده‌سازی نتایج نهایی از قیمت‌های نرمال‌سازی شده آماری (برای مسیر آماری)
     */
    private function prepareFinalResultsFromNormalized(array $statisticallyNormalized, float $P_Lower, float $P_Upper, string $action, float $Po, float $annexLower = 0, float $annexUpper = 0, bool $PoInPrimaryRange = false, float $primaryRangeLower = -1, float $primaryRangeUpper = 1): array
    {
        $results = [];
        foreach ($statisticallyNormalized as $bidder) {
            // بررسی اینکه آیا در دامنه اصلی است
            $inPrimaryRange = ($bidder['statistically_normalized'] >= $primaryRangeLower && $bidder['statistically_normalized'] <= $primaryRangeUpper);
            
            // بررسی اینکه آیا در دامنه الحاقی است
            $inAnnexRange = !$inPrimaryRange && ($bidder['adjusted_price'] >= $annexLower && $bidder['adjusted_price'] <= $annexUpper);
            
            // در محدوده نهایی است اگر قیمت تراز شده (P'_i) در محدوده P_Lower و P_Upper باشد
            // این منطق باید با منطق frontend هماهنگ باشد که بر اساس P_Lower و P_Upper بررسی می‌کند
            // P_Lower و P_Upper از qualifiedPrices محاسبه شده‌اند که شامل قیمت‌های در دامنه اصلی یا الحاقی است
            // توجه: action در این مرحله هنوز تنظیم نشده است (یا '' است یا 'NO_VALID_BIDS' در حالت خاص)
            // پس باید فقط بر اساس P_Lower و P_Upper بررسی کنیم
            $adjustedPrice = floatval($bidder['adjusted_price'] ?? 0);
            
            // بررسی در محدوده بودن
            $inFinalRange = false;
            if ($action !== 'CANCELLED_M62') {
                // بررسی دقیق در محدوده بودن با استفاده از مقایسه float
                $inFinalRange = ($adjustedPrice >= $P_Lower && $adjustedPrice <= $P_Upper);
            }

            $results[] = [
                'bidder_id' => $bidder['bidder_id'],
                'bidder_name' => $bidder['bidder_name'],
                'original_price' => $bidder['original_price'], // P_i
                'normalized_price' => $bidder['adjusted_price'], // P'_i (برای نمایش)
                'adjusted_price' => $bidder['adjusted_price'], // P'_i
                'statistically_normalized' => $bidder['statistically_normalized'], // P''_i
                'technical_score' => $bidder['technical_score'],
                'final_score' => $bidder['adjusted_price'], // برای انتخاب برنده از P'_i استفاده می‌شود
                'in_final_range' => $inFinalRange,
                'in_primary_range' => $inPrimaryRange,
                'in_annex_range' => $inAnnexRange,
                'is_winner' => false,
                'is_winner_first' => false,
                'is_winner_second' => false,
                'rank' => null,
            ];
        }
        return $results;
    }

    /**
     * رتبه‌بندی و تعیین برندگان
     */
    private function rankAndDetermineWinners(array $results, float $P_Lower, float $P_Upper): array
    {
        // مرتب‌سازی بر اساس امتیاز نهایی (کمترین بهترین)
        usort($results, function ($a, $b) {
            return $a['final_score'] <=> $b['final_score'];
        });

        // تعیین رتبه و برندگان
        $rank = 1;
        $winnersInRange = [];

        foreach ($results as $key => $result) {
            $results[$key]['rank'] = $rank;

            // بررسی اینکه آیا در محدوده نهایی است
            // استفاده از adjusted_price یا normalized_price برای بررسی
            $priceToCheck = $result['adjusted_price'] ?? $result['normalized_price'] ?? $result['final_score'] ?? 0;
            
            // بررسی مجدد در محدوده بودن (برای اطمینان)
            $inFinalRange = ($priceToCheck >= $P_Lower && $priceToCheck <= $P_Upper);
            
            // به‌روزرسانی in_final_range اگر تغییر کرده باشد
            if ($inFinalRange !== ($result['in_final_range'] ?? false)) {
                $results[$key]['in_final_range'] = $inFinalRange;
            }

            // اگر در محدوده است، به لیست برندگان اضافه کن
            if ($results[$key]['in_final_range']) {
                $winnersInRange[] = $key;
            }

            $rank++;
        }

        // تعیین برنده اول و دوم از بین کسانی که در محدوده هستند
        if (!empty($winnersInRange)) {
            // برنده اول: کمترین قیمت در محدوده (که در ابتدای لیست مرتب شده است)
            $results[$winnersInRange[0]]['is_winner'] = true;
            $results[$winnersInRange[0]]['is_winner_first'] = true;

            if (count($winnersInRange) > 1) {
                // برنده دوم: دومین کمترین قیمت در محدوده
                $results[$winnersInRange[1]]['is_winner_second'] = true;
            }
        }

        return $results;
    }

    /**
     * ذخیره نتایج ارزیابی در دیتابیس
     * ⚠️ مهم: این تابع برنده‌های ذخیره شده (is_winner_first, is_winner_second) را حفظ می‌کند
     */
    private function saveResults(string $tenderId, array $results): void
    {
        // استفاده از transaction برای اطمینان از یکپارچگی داده‌ها
        DB::beginTransaction();
        
        try {
            // حذف نتایج قبلی
            EvaluationResult::deleteByTenderId($tenderId);

            // ذخیره نتایج جدید
            foreach ($results as $result) {
                try {
                    EvaluationResult::create([
                        'tender_id' => $tenderId,
                        'bidder_id' => $result['bidder_id'],
                        'normalized_price' => $result['normalized_price'] ?? null,
                        'technical_score' => $result['technical_score'] ?? null,
                        'final_score' => $result['final_score'] ?? null,
                        'rank' => $result['rank'] ?? null,
                        'is_winner' => $result['is_winner'] ?? false,
                        'is_winner_first' => $result['is_winner_first'] ?? false,
                        'is_winner_second' => $result['is_winner_second'] ?? false,
                        'notes' => $result['notes'] ?? '',
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Error creating evaluation result', [
                        'tender_id' => $tenderId,
                        'bidder_id' => $result['bidder_id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error saving evaluation results', [
                'tender_id' => $tenderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * تولید لاگ محاسبات
     */
    private function generateCalculationLog(string $tenderId, float $Po, float $mean, float $stdDev, array $ranges, array $results, string $action, string $path): array
    {
        $log = [];

        $log[] = '=== گام ۰: پیش‌پردازش و ورودی‌ها ===';
        $log[] = 'P_o (برآورد به‌هنگام) = ' . number_format($Po, 2) . ' ریال';
        $log[] = 'تعداد مناقصه‌گران: n = ' . count($results);
        
        $log[] = "\n=== ورودی‌ها: قیمت‌های اولیه (P_i) ===";
        foreach ($results as $r) {
            $originalPrice = $r['original_price'] ?? 0;
            $log[] = "  {$r['bidder_name']}: P_i = " . number_format($originalPrice, 2) . " ریال";
        }

        $log[] = "\n=== محاسبه قیمت‌های تراز شده (P'_i) ===";
        $isTwoStage = false;
        $aMax = 100;
        foreach ($results as $r) {
            $originalPrice = $r['original_price'] ?? 0;
            $adjustedPrice = $r['adjusted_price'] ?? $r['normalized_price'] ?? 0;
            $technicalScore = $r['technical_score'] ?? null;
            
            if ($technicalScore !== null && $technicalScore > 0) {
                $isTwoStage = true;
                $aMax = $r['a_max'] ?? 100;
                $log[] = "  {$r['bidder_name']}:";
                $log[] = "    P_i = " . number_format($originalPrice, 2) . " ریال";
                $log[] = "    A_i = " . number_format($technicalScore, 2);
                $log[] = "    A_max = " . number_format($aMax, 2);
                $log[] = "    فرمول: P'_i = P_i × (A_max / A_i)";
                $log[] = "    P'_i = " . number_format($originalPrice, 2) . " × (" . number_format($aMax, 2) . " / " . number_format($technicalScore, 2) . ") = " . number_format($adjustedPrice, 2) . " ریال";
            } else {
                $log[] = "  {$r['bidder_name']}: P_i = " . number_format($originalPrice, 2) . ", P'_i = " . number_format($adjustedPrice, 2) . " ریال (مناقصه تک مرحله‌ای: P'_i = P_i)";
            }
        }

        if ($path === 'STATISTICAL') {
            $log[] = "\n=== گام ۱: بررسی شرط ماده ۶-۲ ===";
            $log[] = "m (میانگین بدون احتساب P_o) = " . number_format($mean, 2) . " ریال";
            $log[] = "بازه مجاز: [" . number_format($ranges['m62_lower'] ?? 0, 2) . ", " . number_format($ranges['m62_upper'] ?? 0, 2) . "]";

            if ($action === 'REVIEW_REQUIRED_M62') {
                $log[] = '⚠ میانگین خارج از بازه مجاز است!';
                $log[] = '→ نتیجه: نیاز به بررسی مجدد P_b و P_o توسط کمیته فنی-بازرگانی';
                $log[] = '→ تصمیم نهایی توسط کمیسیون مناقصه با در نظر گرفتن "صرفه و صلاح دستگاه"';
            } else {
                $log[] = '✓ میانگین در بازه مجاز است';
                
                // ارسال اطلاع I001
                $this->sendAlert('I001', $tenderId, ['mean' => $mean, 'po' => $Po]);

                $log[] = "\n=== گام ۲: محاسبه m_o و s_o با احتساب P_o (ماده ۶-۳) ===";
                $log[] = 'P_o به عنوان یکی از داده‌ها لحاظ می‌شود (n = تعداد مناقصه‌گران + 1)';
                $n = count($results) + 1;
                $log[] = "n = " . count($results) . " + 1 = {$n}";
                
                // محاسبه m_o
                $sum = array_sum(array_column($results, 'adjusted_price')) + $Po;
                $m_o = $sum / $n;
                $log[] = "\nمحاسبه m_o (میانگین):";
                $log[] = "  m_o = (Σ P'_i + P_o) / n";
                $log[] = "  m_o = (" . number_format($sum, 2) . ") / {$n} = " . number_format($m_o, 2) . " ریال";
                
                // محاسبه s_o
                $varianceSum = 0;
                foreach ($results as $r) {
                    $adjPrice = $r['adjusted_price'] ?? $r['normalized_price'] ?? 0;
                    $varianceSum += pow($adjPrice - $m_o, 2);
                }
                $varianceSum += pow($Po - $m_o, 2);
                $variance = $varianceSum / $n;
                $s_o = sqrt($variance);
                $log[] = "\nمحاسبه s_o (انحراف معیار):";
                $log[] = "  s_o = √[Σ(P'_i - m_o)² + (P_o - m_o)²] / n";
                $log[] = "  s_o = √(" . number_format($varianceSum, 2) . " / {$n}) = " . number_format($s_o, 2) . " ریال";

                $log[] = "\n=== گام ۳: نرمال‌سازی آماری (ماده ۶-۳) ===";
                $log[] = "فرمول نرمال‌سازی: P''_i = (P'_i - m_o) / s_o";
                $log[] = "فرمول نرمال‌سازی P_o: P''_o = (P_o - m_o) / s_o";
                
                $m_o = $ranges['m_o'] ?? 0;
                $s_o = $ranges['s_o'] ?? 0;
                
                $log[] = "\nمحاسبه P''_o:";
                $log[] = "  P''_o = (P_o - m_o) / s_o";
                $log[] = "  P''_o = (" . number_format($Po, 2) . " - " . number_format($m_o, 2) . ") / " . number_format($s_o, 2);
                $P_double_prime_o = $ranges['P_double_prime_o'] ?? 0;
                $log[] = "  P''_o = " . number_format($P_double_prime_o, 6);
                
                $log[] = "\nمحاسبه P''_i برای هر مناقصه‌گر:";
                foreach ($results as $r) {
                    $adjustedPrice = $r['adjusted_price'] ?? $r['normalized_price'] ?? 0;
                    $P_double_prime_i = ($adjustedPrice - $m_o) / ($s_o > 0 ? $s_o : 1);
                    $log[] = "  {$r['bidder_name']}: P''_i = (" . number_format($adjustedPrice, 2) . " - " . number_format($m_o, 2) . ") / " . number_format($s_o, 2) . " = " . number_format($P_double_prime_i, 6);
                }
                
                $primaryRangeLower = $ranges['primary_range_lower'] ?? -1;
                $primaryRangeUpper = $ranges['primary_range_upper'] ?? 1;
                $log[] = "\nدامنه اصلی: {$primaryRangeLower} ≤ P''_i ≤ {$primaryRangeUpper}";
                $inPrimaryCount = count(array_filter($results, fn($r) => ($r['in_primary_range'] ?? false)));
                $log[] = "تعداد مناقصه‌گران در دامنه اصلی: " . $inPrimaryCount;

                $log[] = "\n=== گام ۴: دامنه الحاقی (تبصره بند ۶-۳-۳) ===";
                $PoInPrimary = $ranges['Po_in_primary_range'] ?? false;
                if ($PoInPrimary) {
                    $log[] = "P''_o در دامنه اصلی است → دامنه الحاقی: ±20% از P_o";
                } else {
                    $log[] = "P''_o در دامنه اصلی نیست → دامنه الحاقی: ±10% از P_o";
                }
                $log[] = "بازه الحاقی: [" . number_format($ranges['annex_range_lower'] ?? 0, 2) . ", " . number_format($ranges['annex_range_upper'] ?? 0, 2) . "]";
                
                $inAnnexCount = count(array_filter($results, fn($r) => ($r['in_annex_range'] ?? false)));
                $log[] = "تعداد مناقصه‌گران در دامنه الحاقی: " . $inAnnexCount;

                $log[] = "\nدامنه قیمت متناسب نهایی:";
                $log[] = "  P_Lower = " . number_format($ranges['final_lower'] ?? 0, 2) . " ریال";
                $log[] = "  P_Upper = " . number_format($ranges['final_upper'] ?? 0, 2) . " ریال";
            }
        } else {
            // مسیر ساده
            $log[] = "\nمسیر ساده (ماده ۶-۱):";
            $log[] = "  P_Lower = 0.9 × P_o = " . number_format($ranges['final_lower'] ?? 0, 2) . " ریال";
            $log[] = "  P_Upper = 1.1 × P_o = " . number_format($ranges['final_upper'] ?? 0, 2) . " ریال";
        }

        $log[] = "\n=== گام ۵: تعیین برنده (ماده ۷) ===";
        $winners = array_values(array_filter($results, fn($r) => $r['is_winner_first'] ?? false));
        $winner = !empty($winners) ? $winners[0] : null;
        if ($winner) {
            $log[] = "✓ برنده مناقصه: " . $winner['bidder_name'];
            $finalScore = $winner['final_score'] ?? $winner['adjusted_price'] ?? $winner['normalized_price'] ?? 0;
            $log[] = "  P_LQP (کمترین قیمت متناسب) = " . number_format($finalScore, 2) . " ریال";
        } else {
            $log[] = '✗ هیچ پیشنهاد معتبری در دامنه قیمت متناسب وجود ندارد';
        }

        return $log;
    }

    /**
     * دریافت نتایج ارزیابی از دیتابیس
     * اگر ارزیابی انجام شده، محدوده‌ها را از نتایج محاسبه می‌کند
     */
    public function getResults(string $tenderId): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            throw new \Exception('مناقصه یافت نشد');
        }

        $results = EvaluationResult::getByTenderId($tenderId);
        
        // اگر ارزیابی انجام شده، محدوده‌ها را محاسبه کن
        $ranges = null;
        $hasEvaluationCalculatedAt = \Illuminate\Support\Facades\Schema::hasColumn('tenders', 'evaluation_calculated_at');
        if ((!$hasEvaluationCalculatedAt || $tender->evaluation_calculated_at) && !empty($results)) {
            // محاسبه محدوده‌ها از نتایج ارزیابی
            // استفاده از قیمت‌های تراز شده (normalized_price) که در دیتابیس ذخیره شده
            $normalizedPrices = array_filter(
                array_column($results, 'normalized_price'),
                function($price) {
                    return $price !== null && is_numeric($price) && $price > 0;
                }
            );
            
            if (!empty($normalizedPrices)) {
                $normalizedPrices = array_values($normalizedPrices);
                $count = count($normalizedPrices);
                
                if ($count > 0) {
                    $mean = array_sum($normalizedPrices) / $count;
                    $variance = array_sum(array_map(function($x) use ($mean) {
                        return pow($x - $mean, 2);
                    }, $normalizedPrices)) / $count;
                    $stdDev = sqrt($variance);
                    
                    // محاسبه محدوده نهایی: میانگین ± 2 انحراف معیار
                    // اما برای دقت بیشتر، از کمترین و بیشترین قیمت در دامنه استفاده می‌کنیم
                    $inRangePrices = array_filter($normalizedPrices, function($price) use ($mean, $stdDev) {
                        $lowerBound = $mean - (2 * $stdDev);
                        $upperBound = $mean + (2 * $stdDev);
                        return $price >= $lowerBound && $price <= $upperBound;
                    });
                    
                    if (!empty($inRangePrices)) {
                        $inRangePrices = array_values($inRangePrices);
                        $P_Lower = min($inRangePrices);
                        $P_Upper = max($inRangePrices);
                    } else {
                        // اگر هیچ قیمتی در دامنه نیست، از تمام قیمت‌ها استفاده کن
                        $P_Lower = min($normalizedPrices);
                        $P_Upper = max($normalizedPrices);
                    }
                    
                    $ranges = [
                        'final_lower' => $P_Lower,
                        'final_upper' => $P_Upper,
                        'm_o' => $mean,
                        's_o' => $stdDev,
                        'm_prime_o' => $mean,
                        's_prime_o' => $stdDev,
                    ];
                }
            }
        }

        $hasEvaluationCalculatedAt = \Illuminate\Support\Facades\Schema::hasColumn('tenders', 'evaluation_calculated_at');
        return [
            'tender_id' => $tenderId,
            'Po' => floatval($tender->po ?? 0),
            'evaluation_is_valid' => $hasEvaluationCalculatedAt ? ($tender->evaluation_calculated_at !== null) : false,
            'results' => $results,
            'ranges' => $ranges,
        ];
    }
}


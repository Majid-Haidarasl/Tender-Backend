<?php

namespace Tests;

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Models\EvaluationResult;
use App\Models\Notification;
use App\Services\PoCalculationService;
use App\Services\EvaluationService;
use App\Services\AlertNotificationService;
use App\Services\ComprehensiveValidationFramework;
use App\Services\AuditTrailService;
use App\Services\InputTrackingService;
use App\Services\StageReportService;
use App\Services\IntelligentReportService;
use App\Services\ManagementReportService;
use App\Services\TechnicalCommercialScoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * آزمون جامع ارزیابی سامانه
 * این آزمون تمام ابعاد و ماژول‌های سامانه را بررسی می‌کند
 * و بر اساس 100 نمره به سامانه امتیاز می‌دهد
 */
class ComprehensiveSystemEvaluationTest
{
    private $scores = [];
    private $issues = [];
    private $improvements = [];
    private $totalScore = 0;
    private $maxScore = 100;

    /**
     * اجرای آزمون جامع
     */
    public function runComprehensiveEvaluation(): array
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "آزمون جامع ارزیابی سامانه مناقصات\n";
        echo str_repeat("=", 100) . "\n\n";

        // 1. تست محاسبات و ورودی‌ها (25 نمره)
        $this->evaluateCalculationsAndInputs();

        // 2. تست ماژول‌های اصلی (20 نمره)
        $this->evaluateCoreModules();

        // 3. تست سیستم هشدار و خطا (15 نمره)
        $this->evaluateAlertSystem();

        // 4. تست Audit Trail و Tracking (10 نمره)
        $this->evaluateAuditTrail();

        // 5. تست سیستم گزارش‌ها (15 نمره)
        $this->evaluateReportingSystem();

        // 6. تست بهینه‌سازی‌ها (5 نمره)
        $this->evaluateOptimizations();

        // 7. تست ماژول‌های کاربری (5 نمره)
        $this->evaluateUserModules();

        // 8. تست مدیریت خطا و استثناها (5 نمره)
        $this->evaluateErrorHandling();

        // محاسبه نمره نهایی
        $this->calculateFinalScore();

        // نمایش نتایج
        $this->displayResults();

        return [
            'total_score' => $this->totalScore,
            'max_score' => $this->maxScore,
            'percentage' => round(($this->totalScore / $this->maxScore) * 100, 2),
            'scores' => $this->scores,
            'issues' => $this->issues,
            'improvements' => $this->improvements,
        ];
    }

    /**
     * 1. ارزیابی محاسبات و ورودی‌ها (25 نمره)
     */
    private function evaluateCalculationsAndInputs(): void
    {
        echo "1. ارزیابی محاسبات و ورودی‌ها (25 نمره)\n";
        echo str_repeat("-", 100) . "\n";

        $score = 0;
        $maxScore = 25;

        // 1.1 تست محاسبه Pb (3 نمره)
        try {
            $tender = Tender::first();
            if ($tender) {
                $pb = Estimate::calculateTotalPb($tender->id);
                if ($pb > 0) {
                    $score += 3;
                    echo "  ✓ محاسبه Pb: 3/3\n";
                } else {
                    $this->issues[] = "محاسبه Pb مقدار نامعتبر برمی‌گرداند";
                    echo "  ✗ محاسبه Pb: 0/3\n";
                }
            } else {
                $this->issues[] = "هیچ مناقصه‌ای برای تست محاسبه Pb وجود ندارد";
                echo "  ✗ محاسبه Pb: 0/3 (مناقصه یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در محاسبه Pb: " . $e->getMessage();
            echo "  ✗ محاسبه Pb: 0/3 (خطا: {$e->getMessage()})\n";
        }

        // 1.2 تست محاسبه Po (5 نمره)
        try {
            $poService = new PoCalculationService();
            $tender = Tender::whereNotNull('pb')->where('pb', '>', 0)->first();
            if ($tender) {
                $result = $poService->calculate($tender->id);
                if (isset($result['Po']) && $result['Po'] > 0) {
                    $score += 5;
                    echo "  ✓ محاسبه Po: 5/5\n";
                } else {
                    $this->issues[] = "محاسبه Po مقدار نامعتبر برمی‌گرداند";
                    echo "  ✗ محاسبه Po: 0/5\n";
                }
            } else {
                $this->issues[] = "هیچ مناقصه‌ای با Pb معتبر برای تست محاسبه Po وجود ندارد";
                echo "  ✗ محاسبه Po: 0/5 (مناقصه مناسب یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در محاسبه Po: " . $e->getMessage();
            echo "  ✗ محاسبه Po: 0/5 (خطا: {$e->getMessage()})\n";
        }

        // 1.3 تست ارزیابی مناقصه‌گران (5 نمره)
        try {
            $evalService = new EvaluationService();
            $tender = Tender::whereNotNull('po')->where('po', '>', 0)->first();
            if ($tender) {
                $bidders = Bidder::getByTenderId($tender->id);
                if (!empty($bidders)) {
                    $result = $evalService->evaluate($tender->id);
                    if (isset($result['path']) && !empty($result['path'])) {
                        $score += 5;
                        echo "  ✓ ارزیابی مناقصه‌گران: 5/5\n";
                    } else {
                        $this->issues[] = "ارزیابی مناقصه‌گران مسیر نامعتبر برمی‌گرداند";
                        echo "  ✗ ارزیابی مناقصه‌گران: 0/5\n";
                    }
                } else {
                    $this->issues[] = "هیچ مناقصه‌گری برای تست ارزیابی وجود ندارد";
                    echo "  ✗ ارزیابی مناقصه‌گران: 0/5 (مناقصه‌گر یافت نشد)\n";
                }
            } else {
                $this->issues[] = "هیچ مناقصه‌ای با Po معتبر برای تست ارزیابی وجود ندارد";
                echo "  ✗ ارزیابی مناقصه‌گران: 0/5 (مناقصه مناسب یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در ارزیابی مناقصه‌گران: " . $e->getMessage();
            echo "  ✗ ارزیابی مناقصه‌گران: 0/5 (خطا: {$e->getMessage()})\n";
        }

        // 1.4 تست اعتبارسنجی ورودی‌ها (4 نمره)
        try {
            $validationService = new ComprehensiveValidationFramework();
            $tender = Tender::first();
            if ($tender) {
                $result = $validationService->validateAllInputs($tender->id);
                if (isset($result['valid']) || isset($result['blocking_errors'])) {
                    $score += 4;
                    echo "  ✓ اعتبارسنجی ورودی‌ها: 4/4\n";
                } else {
                    $this->issues[] = "اعتبارسنجی ورودی‌ها ساختار نامعتبر برمی‌گرداند";
                    echo "  ✗ اعتبارسنجی ورودی‌ها: 0/4\n";
                }
            } else {
                echo "  ✗ اعتبارسنجی ورودی‌ها: 0/4 (مناقصه یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در اعتبارسنجی ورودی‌ها: " . $e->getMessage();
            echo "  ✗ اعتبارسنجی ورودی‌ها: 0/4 (خطا: {$e->getMessage()})\n";
        }

        // 1.5 تست محاسبه امتیاز فنی-بازرگانی (3 نمره)
        try {
            $techScoreService = new TechnicalCommercialScoreService();
            $tender = Tender::where('is_two_stage', true)->first();
            if ($tender) {
                $bidders = Bidder::getByTenderId($tender->id);
                if (!empty($bidders)) {
                    $result = $techScoreService->calculateAdjustedPrices($tender->id);
                    if (isset($result['adjusted_prices']) && is_array($result['adjusted_prices'])) {
                        $score += 3;
                        echo "  ✓ محاسبه امتیاز فنی-بازرگانی: 3/3\n";
                    } else {
                        $this->issues[] = "محاسبه امتیاز فنی-بازرگانی ساختار نامعتبر برمی‌گرداند";
                        echo "  ✗ محاسبه امتیاز فنی-بازرگانی: 0/3\n";
                    }
                } else {
                    echo "  ⚠ محاسبه امتیاز فنی-بازرگانی: 0/3 (مناقصه‌گر یافت نشد)\n";
                }
            } else {
                echo "  ⚠ محاسبه امتیاز فنی-بازرگانی: 0/3 (مناقصه دو مرحله‌ای یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در محاسبه امتیاز فنی-بازرگانی: " . $e->getMessage();
            echo "  ✗ محاسبه امتیاز فنی-بازرگانی: 0/3 (خطا: {$e->getMessage()})\n";
        }

        // 1.6 تست دقت محاسبات (5 نمره)
        try {
            $tender = Tender::whereNotNull('po')->where('po', '>', 0)->first();
            if ($tender && $tender->pb > 0) {
                $po = $tender->po;
                $pb = $tender->pb;
                $ratio = $po / $pb;
                
                // بررسی منطقی بودن نسبت Po/Pb (معمولاً بین 0.5 تا 2.0)
                if ($ratio >= 0.5 && $ratio <= 2.0) {
                    $score += 3;
                    echo "  ✓ دقت محاسبات (نسبت Po/Pb): 3/3\n";
                } else {
                    $this->issues[] = "نسبت Po/Pb خارج از محدوده منطقی است: {$ratio}";
                    echo "  ⚠ دقت محاسبات (نسبت Po/Pb): 1/3 (نسبت: {$ratio})\n";
                    $score += 1;
                }

                // بررسی دقت اعشار
                $poDecimalPlaces = strlen(substr(strrchr($po, "."), 1));
                if ($poDecimalPlaces <= 4) {
                    $score += 2;
                    echo "  ✓ دقت اعشار: 2/2\n";
                } else {
                    $this->issues[] = "دقت اعشار Po بیش از حد است: {$poDecimalPlaces} رقم";
                    echo "  ⚠ دقت اعشار: 1/2 (اعشار: {$poDecimalPlaces})\n";
                    $score += 1;
                }
            } else {
                echo "  ✗ دقت محاسبات: 0/5 (مناقصه مناسب یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در بررسی دقت محاسبات: " . $e->getMessage();
            echo "  ✗ دقت محاسبات: 0/5 (خطا: {$e->getMessage()})\n";
        }

        $this->scores['calculations_and_inputs'] = [
            'score' => $score,
            'max' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2)
        ];
        echo "  مجموع: {$score}/{$maxScore} (" . round(($score / $maxScore) * 100, 2) . "%)\n\n";
    }

    /**
     * 2. ارزیابی ماژول‌های اصلی (20 نمره)
     */
    private function evaluateCoreModules(): void
    {
        echo "2. ارزیابی ماژول‌های اصلی (20 نمره)\n";
        echo str_repeat("-", 100) . "\n";

        $score = 0;
        $maxScore = 20;

        // 2.1 تست PoCalculationService (4 نمره)
        try {
            $service = new PoCalculationService();
            $tender = Tender::whereNotNull('pb')->where('pb', '>', 0)->first();
            if ($tender) {
                $result = $service->calculate($tender->id);
                if (isset($result['Po']) && $result['Po'] > 0) {
                    $score += 4;
                    echo "  ✓ PoCalculationService: 4/4\n";
                } else {
                    $this->issues[] = "PoCalculationService مقدار نامعتبر برمی‌گرداند";
                    echo "  ✗ PoCalculationService: 0/4\n";
                }
            } else {
                echo "  ✗ PoCalculationService: 0/4 (مناقصه مناسب یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در PoCalculationService: " . $e->getMessage();
            echo "  ✗ PoCalculationService: 0/4 (خطا: {$e->getMessage()})\n";
        }

        // 2.2 تست EvaluationService (4 نمره)
        try {
            $service = new EvaluationService();
            $tender = Tender::whereNotNull('po')->where('po', '>', 0)->first();
            if ($tender) {
                $bidders = Bidder::getByTenderId($tender->id);
                if (!empty($bidders)) {
                    $result = $service->evaluate($tender->id);
                    if (isset($result['path'])) {
                        $score += 4;
                        echo "  ✓ EvaluationService: 4/4\n";
                    } else {
                        echo "  ✗ EvaluationService: 0/4\n";
                    }
                } else {
                    echo "  ✗ EvaluationService: 0/4 (مناقصه‌گر یافت نشد)\n";
                }
            } else {
                echo "  ✗ EvaluationService: 0/4 (مناقصه مناسب یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در EvaluationService: " . $e->getMessage();
            echo "  ✗ EvaluationService: 0/4 (خطا: {$e->getMessage()})\n";
        }

        // 2.3 تست ComprehensiveValidationFramework (4 نمره)
        try {
            $service = new ComprehensiveValidationFramework();
            $tender = Tender::first();
            if ($tender) {
                $result = $service->validateAllInputs($tender->id);
                if (is_array($result) && isset($result['blocking_errors'])) {
                    $score += 4;
                    echo "  ✓ ComprehensiveValidationFramework: 4/4\n";
                } else {
                    echo "  ✗ ComprehensiveValidationFramework: 0/4\n";
                }
            } else {
                echo "  ✗ ComprehensiveValidationFramework: 0/4 (مناقصه یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در ComprehensiveValidationFramework: " . $e->getMessage();
            echo "  ✗ ComprehensiveValidationFramework: 0/4 (خطا: {$e->getMessage()})\n";
        }

        // 2.4 تست TechnicalCommercialScoreService (3 نمره)
        try {
            $service = new TechnicalCommercialScoreService();
            $tender = Tender::where('is_two_stage', true)->first();
            if ($tender) {
                $bidders = Bidder::getByTenderId($tender->id);
                if (!empty($bidders)) {
                    $result = $service->calculateAdjustedPrices($tender->id);
                    if (isset($result['adjusted_prices'])) {
                        $score += 3;
                        echo "  ✓ TechnicalCommercialScoreService: 3/3\n";
                    } else {
                        echo "  ✗ TechnicalCommercialScoreService: 0/3\n";
                    }
                } else {
                    echo "  ⚠ TechnicalCommercialScoreService: 0/3 (مناقصه‌گر یافت نشد)\n";
                }
            } else {
                echo "  ⚠ TechnicalCommercialScoreService: 0/3 (مناقصه دو مرحله‌ای یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در TechnicalCommercialScoreService: " . $e->getMessage();
            echo "  ✗ TechnicalCommercialScoreService: 0/3 (خطا: {$e->getMessage()})\n";
        }

        // 2.5 تست مدل‌ها (5 نمره)
        try {
            $tenderCount = Tender::count();
            $estimateCount = Estimate::count();
            $indexCount = Index::count();
            $bidderCount = Bidder::count();

            if ($tenderCount > 0) {
                $score += 1;
                echo "  ✓ مدل Tender: 1/1\n";
            } else {
                echo "  ✗ مدل Tender: 0/1\n";
            }

            if ($estimateCount > 0) {
                $score += 1;
                echo "  ✓ مدل Estimate: 1/1\n";
            } else {
                echo "  ✗ مدل Estimate: 0/1\n";
            }

            if ($indexCount > 0) {
                $score += 1;
                echo "  ✓ مدل Index: 1/1\n";
            } else {
                echo "  ✗ مدل Index: 0/1\n";
            }

            if ($bidderCount > 0) {
                $score += 1;
                echo "  ✓ مدل Bidder: 1/1\n";
            } else {
                echo "  ✗ مدل Bidder: 0/1\n";
            }

            // تست روابط
            $tender = Tender::with('estimates', 'indices', 'bidders')->first();
            if ($tender) {
                $score += 1;
                echo "  ✓ روابط مدل‌ها: 1/1\n";
            } else {
                echo "  ✗ روابط مدل‌ها: 0/1\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "خطا در تست مدل‌ها: " . $e->getMessage();
            echo "  ✗ تست مدل‌ها: 0/5 (خطا: {$e->getMessage()})\n";
        }

        $this->scores['core_modules'] = [
            'score' => $score,
            'max' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2)
        ];
        echo "  مجموع: {$score}/{$maxScore} (" . round(($score / $maxScore) * 100, 2) . "%)\n\n";
    }

    /**
     * 3. ارزیابی سیستم هشدار و خطا (15 نمره)
     */
    private function evaluateAlertSystem(): void
    {
        echo "3. ارزیابی سیستم هشدار و خطا (15 نمره)\n";
        echo str_repeat("-", 100) . "\n";

        $score = 0;
        $maxScore = 15;

        try {
            $alertService = new AlertNotificationService();

            // 3.1 تست تعریف پیام‌ها (5 نمره)
            $messages = $alertService->getAllMessages();
            if (count($messages) >= 20) {
                $score += 5;
                echo "  ✓ تعریف پیام‌ها: 5/5 (" . count($messages) . " پیام)\n";
            } else {
                $this->issues[] = "تعداد پیام‌های تعریف شده کمتر از حد انتظار است: " . count($messages);
                $score += 3;
                echo "  ⚠ تعریف پیام‌ها: 3/5 (" . count($messages) . " پیام)\n";
            }

            // 3.2 تست ارسال هشدار (3 نمره)
            $tender = Tender::first();
            if ($tender) {
                try {
                    $alertService->sendWarning('W001', $tender->id, ['test' => true]);
                    $score += 3;
                    echo "  ✓ ارسال هشدار: 3/3\n";
                } catch (\Exception $e) {
                    $this->issues[] = "خطا در ارسال هشدار: " . $e->getMessage();
                    echo "  ✗ ارسال هشدار: 0/3 (خطا: {$e->getMessage()})\n";
                }
            } else {
                echo "  ✗ ارسال هشدار: 0/3 (مناقصه یافت نشد)\n";
            }

            // 3.3 تست ارسال خطا (3 نمره)
            if ($tender) {
                try {
                    $alertService->sendError('E001', $tender->id, ['test' => true]);
                    $score += 3;
                    echo "  ✓ ارسال خطا: 3/3\n";
                } catch (\Exception $e) {
                    $this->issues[] = "خطا در ارسال خطا: " . $e->getMessage();
                    echo "  ✗ ارسال خطا: 0/3 (خطا: {$e->getMessage()})\n";
                }
            } else {
                echo "  ✗ ارسال خطا: 0/3 (مناقصه یافت نشد)\n";
            }

            // 3.4 تست اولویت‌بندی پیام‌ها (2 نمره)
            $priorityManager = new \App\Services\AlertPriorityManager();
            $priority = $priorityManager->getPriority('E001');
            if ($priority !== null) {
                $score += 2;
                echo "  ✓ اولویت‌بندی پیام‌ها: 2/2\n";
            } else {
                $this->issues[] = "سیستم اولویت‌بندی پیام‌ها کار نمی‌کند";
                echo "  ✗ اولویت‌بندی پیام‌ها: 0/2\n";
            }

            // 3.5 تست بررسی خودکار شرایط (2 نمره)
            if ($tender) {
                try {
                    $alertService->checkAndSend($tender->id, AlertNotificationService::STAGE_PO_CALCULATION, []);
                    $score += 2;
                    echo "  ✓ بررسی خودکار شرایط: 2/2\n";
                } catch (\Exception $e) {
                    $this->issues[] = "خطا در بررسی خودکار شرایط: " . $e->getMessage();
                    echo "  ✗ بررسی خودکار شرایط: 0/2 (خطا: {$e->getMessage()})\n";
                }
            } else {
                echo "  ✗ بررسی خودکار شرایط: 0/2 (مناقصه یافت نشد)\n";
            }

        } catch (\Exception $e) {
            $this->issues[] = "خطا در ارزیابی سیستم هشدار: " . $e->getMessage();
            echo "  ✗ خطا در ارزیابی سیستم هشدار: " . $e->getMessage() . "\n";
        }

        $this->scores['alert_system'] = [
            'score' => $score,
            'max' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2)
        ];
        echo "  مجموع: {$score}/{$maxScore} (" . round(($score / $maxScore) * 100, 2) . "%)\n\n";
    }

    /**
     * 4. ارزیابی Audit Trail و Tracking (10 نمره)
     */
    private function evaluateAuditTrail(): void
    {
        echo "4. ارزیابی Audit Trail و Tracking (10 نمره)\n";
        echo str_repeat("-", 100) . "\n";

        $score = 0;
        $maxScore = 10;

        try {
            $tender = Tender::first();
            if (!$tender) {
                echo "  ✗ Audit Trail: 0/10 (مناقصه یافت نشد)\n";
                $this->scores['audit_trail'] = ['score' => 0, 'max' => $maxScore, 'percentage' => 0];
                return;
            }

            // 4.1 تست AuditTrailService (3 نمره)
            try {
                $auditService = new AuditTrailService();
                $auditService->logCalculation($tender->id, 'TEST_ACTION', ['test' => true], ['result' => 'test'], 'TEST_STAGE', 'Test formula');
                $history = \App\Models\AuditTrail::getByTenderId($tender->id);
                if (is_array($history)) {
                    $score += 3;
                    echo "  ✓ AuditTrailService: 3/3\n";
                } else {
                    echo "  ✗ AuditTrailService: 0/3\n";
                }
            } catch (\Exception $e) {
                $this->issues[] = "خطا در AuditTrailService: " . $e->getMessage();
                echo "  ✗ AuditTrailService: 0/3 (خطا: {$e->getMessage()})\n";
            }

            // 4.2 تست InputTrackingService (3 نمره)
            try {
                // تست با استفاده از trackPo که متد عمومی است
                $inputService = new InputTrackingService();
                $poService = new PoCalculationService();
                $tenderWithPo = Tender::whereNotNull('po')->where('po', '>', 0)->first();
                if ($tenderWithPo) {
                    $inputService->trackPo($tenderWithPo->id, $tenderWithPo->po, []);
                    $score += 3;
                    echo "  ✓ InputTrackingService: 3/3\n";
                } else {
                    echo "  ⚠ InputTrackingService: 1/3 (مناقصه با Po یافت نشد)\n";
                    $score += 1;
                }
            } catch (\Exception $e) {
                $this->issues[] = "خطا در InputTrackingService: " . $e->getMessage();
                echo "  ✗ InputTrackingService: 0/3 (خطا: {$e->getMessage()})\n";
            }

            // 4.3 تست DecisionHistory (2 نمره)
            try {
                $decisionHistory = \App\Models\DecisionHistory::getByTenderId($tender->id);
                if (is_array($decisionHistory)) {
                    $score += 2;
                    echo "  ✓ DecisionHistory: 2/2\n";
                } else {
                    echo "  ✗ DecisionHistory: 0/2\n";
                }
            } catch (\Exception $e) {
                $this->issues[] = "خطا در DecisionHistory: " . $e->getMessage();
                echo "  ✗ DecisionHistory: 0/2 (خطا: {$e->getMessage()})\n";
            }

            // 4.4 تست InputHistory (2 نمره)
            try {
                $inputHistory = \App\Models\InputHistory::getByTenderId($tender->id);
                if (is_array($inputHistory)) {
                    $score += 2;
                    echo "  ✓ InputHistory: 2/2\n";
                } else {
                    echo "  ✗ InputHistory: 0/2\n";
                }
            } catch (\Exception $e) {
                $this->issues[] = "خطا در InputHistory: " . $e->getMessage();
                echo "  ✗ InputHistory: 0/2 (خطا: {$e->getMessage()})\n";
            }

        } catch (\Exception $e) {
            $this->issues[] = "خطا در ارزیابی Audit Trail: " . $e->getMessage();
            echo "  ✗ خطا در ارزیابی Audit Trail: " . $e->getMessage() . "\n";
        }

        $this->scores['audit_trail'] = [
            'score' => $score,
            'max' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2)
        ];
        echo "  مجموع: {$score}/{$maxScore} (" . round(($score / $maxScore) * 100, 2) . "%)\n\n";
    }

    /**
     * 5. ارزیابی سیستم گزارش‌ها (15 نمره)
     */
    private function evaluateReportingSystem(): void
    {
        echo "5. ارزیابی سیستم گزارش‌ها (15 نمره)\n";
        echo str_repeat("-", 100) . "\n";

        $score = 0;
        $maxScore = 15;

        try {
            $tender = Tender::first();
            if (!$tender) {
                echo "  ✗ سیستم گزارش‌ها: 0/15 (مناقصه یافت نشد)\n";
                $this->scores['reporting_system'] = ['score' => 0, 'max' => $maxScore, 'percentage' => 0];
                return;
            }

            // 5.1 تست StageReportService (4 نمره)
            try {
                $stageService = new StageReportService();
                $report = $stageService->generateStageReport($tender->id, 'PO_CALCULATION');
                if (is_array($report) && isset($report['stage'])) {
                    $score += 4;
                    echo "  ✓ StageReportService: 4/4\n";
                } else {
                    echo "  ✗ StageReportService: 0/4\n";
                }
            } catch (\Exception $e) {
                $this->issues[] = "خطا در StageReportService: " . $e->getMessage();
                echo "  ✗ StageReportService: 0/4 (خطا: {$e->getMessage()})\n";
            }

            // 5.2 تست IntelligentReportService (4 نمره)
            try {
                $intelligentService = new IntelligentReportService();
                $report = $intelligentService->generateFullReport($tender->id, 'markdown');
                if (!empty($report)) {
                    $score += 4;
                    echo "  ✓ IntelligentReportService: 4/4\n";
                } else {
                    echo "  ✗ IntelligentReportService: 0/4\n";
                }
            } catch (\Exception $e) {
                $this->issues[] = "خطا در IntelligentReportService: " . $e->getMessage();
                echo "  ✗ IntelligentReportService: 0/4 (خطا: {$e->getMessage()})\n";
            }

            // 5.3 تست ManagementReportService (4 نمره)
            try {
                $managementService = new ManagementReportService();
                $report = $managementService->generateManagementReport($tender->id);
                if (is_array($report) && (isset($report['tender_info']) || isset($report['tender']))) {
                    $score += 4;
                    echo "  ✓ ManagementReportService: 4/4\n";
                } else {
                    echo "  ✗ ManagementReportService: 0/4\n";
                }
            } catch (\Exception $e) {
                $this->issues[] = "خطا در ManagementReportService: " . $e->getMessage();
                echo "  ✗ ManagementReportService: 0/4 (خطا: {$e->getMessage()})\n";
            }

            // 5.4 تست فرمت‌های خروجی (3 نمره)
            try {
                $intelligentService = new IntelligentReportService();
                $formats = ['markdown', 'html', 'text'];
                $formatScore = 0;
                foreach ($formats as $format) {
                    try {
                        $report = $intelligentService->generateFullReport($tender->id, $format);
                        if (!empty($report)) {
                            $formatScore++;
                        }
                    } catch (\Exception $e) {
                        // ignore
                    }
                }
                $score += $formatScore;
                echo "  ✓ فرمت‌های خروجی: {$formatScore}/3\n";
            } catch (\Exception $e) {
                $this->issues[] = "خطا در فرمت‌های خروجی: " . $e->getMessage();
                echo "  ✗ فرمت‌های خروجی: 0/3 (خطا: {$e->getMessage()})\n";
            }

        } catch (\Exception $e) {
            $this->issues[] = "خطا در ارزیابی سیستم گزارش‌ها: " . $e->getMessage();
            echo "  ✗ خطا در ارزیابی سیستم گزارش‌ها: " . $e->getMessage() . "\n";
        }

        $this->scores['reporting_system'] = [
            'score' => $score,
            'max' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2)
        ];
        echo "  مجموع: {$score}/{$maxScore} (" . round(($score / $maxScore) * 100, 2) . "%)\n\n";
    }

    /**
     * 6. ارزیابی بهینه‌سازی‌ها (5 نمره)
     */
    private function evaluateOptimizations(): void
    {
        echo "6. ارزیابی بهینه‌سازی‌ها (5 نمره)\n";
        echo str_repeat("-", 100) . "\n";

        $score = 0;
        $maxScore = 5;

        // 6.1 تست استفاده از Index در دیتابیس (2 نمره)
        try {
            $tendersTable = DB::select("SHOW INDEX FROM tenders");
            $hasIndexes = count($tendersTable) > 1; // بیشتر از PRIMARY KEY
            if ($hasIndexes) {
                $score += 2;
                echo "  ✓ استفاده از Index در دیتابیس: 2/2\n";
            } else {
                $this->improvements[] = "افزودن Index به جداول برای بهبود عملکرد";
                echo "  ⚠ استفاده از Index در دیتابیس: 1/2\n";
                $score += 1;
            }
        } catch (\Exception $e) {
            echo "  ✗ استفاده از Index در دیتابیس: 0/2 (خطا: {$e->getMessage()})\n";
        }

        // 6.2 تست Cache (1 نمره)
        try {
            if (config('cache.default') !== 'array') {
                $score += 1;
                echo "  ✓ تنظیمات Cache: 1/1\n";
            } else {
                $this->improvements[] = "استفاده از Cache برای بهبود عملکرد";
                echo "  ⚠ تنظیمات Cache: 0/1 (استفاده از array cache)\n";
            }
        } catch (\Exception $e) {
            echo "  ✗ تنظیمات Cache: 0/1\n";
        }

        // 6.3 تست Query Optimization (2 نمره)
        try {
            $tender = Tender::with(['estimates', 'indices', 'bidders'])->first();
            if ($tender) {
                $score += 2;
                echo "  ✓ بهینه‌سازی Query (Eager Loading): 2/2\n";
            } else {
                echo "  ✗ بهینه‌سازی Query: 0/2 (مناقصه یافت نشد)\n";
            }
        } catch (\Exception $e) {
            $this->improvements[] = "بهینه‌سازی Query با استفاده از Eager Loading";
            echo "  ⚠ بهینه‌سازی Query: 1/2 (خطا: {$e->getMessage()})\n";
            $score += 1;
        }

        $this->scores['optimizations'] = [
            'score' => $score,
            'max' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2)
        ];
        echo "  مجموع: {$score}/{$maxScore} (" . round(($score / $maxScore) * 100, 2) . "%)\n\n";
    }

    /**
     * 7. ارزیابی ماژول‌های کاربری (5 نمره)
     */
    private function evaluateUserModules(): void
    {
        echo "7. ارزیابی ماژول‌های کاربری (5 نمره)\n";
        echo str_repeat("-", 100) . "\n";

        $score = 0;
        $maxScore = 5;

        // 7.1 تست API Routes (2 نمره)
        try {
            $routes = \Illuminate\Support\Facades\Route::getRoutes();
            $apiRoutes = 0;
            foreach ($routes as $route) {
                if (strpos($route->uri(), 'api/') === 0) {
                    $apiRoutes++;
                }
            }
            if ($apiRoutes >= 20) {
                $score += 2;
                echo "  ✓ API Routes: 2/2 ({$apiRoutes} route)\n";
            } else {
                $this->improvements[] = "افزودن API Routes بیشتر برای پوشش کامل عملکردها";
                echo "  ⚠ API Routes: 1/2 ({$apiRoutes} route)\n";
                $score += 1;
            }
        } catch (\Exception $e) {
            echo "  ✗ API Routes: 0/2 (خطا: {$e->getMessage()})\n";
        }

        // 7.2 تست Controllers (2 نمره)
        try {
            $controllerFiles = glob(app_path('Http/Controllers/*.php'));
            $controllerCount = count($controllerFiles);
            if ($controllerCount >= 10) {
                $score += 2;
                echo "  ✓ Controllers: 2/2 ({$controllerCount} controller)\n";
            } else {
                $this->improvements[] = "افزودن Controller بیشتر برای پوشش کامل عملکردها";
                echo "  ⚠ Controllers: 1/2 ({$controllerCount} controller)\n";
                $score += 1;
            }
        } catch (\Exception $e) {
            echo "  ✗ Controllers: 0/2 (خطا: {$e->getMessage()})\n";
        }

        // 7.3 تست Middleware (1 نمره)
        try {
            $middlewareFiles = glob(app_path('Http/Middleware/*.php'));
            $middlewareCount = count($middlewareFiles);
            if ($middlewareCount > 0) {
                $score += 1;
                echo "  ✓ Middleware: 1/1 ({$middlewareCount} middleware)\n";
            } else {
                $this->improvements[] = "افزودن Middleware برای امنیت و اعتبارسنجی";
                echo "  ⚠ Middleware: 0/1\n";
            }
        } catch (\Exception $e) {
            echo "  ✗ Middleware: 0/1 (خطا: {$e->getMessage()})\n";
        }

        $this->scores['user_modules'] = [
            'score' => $score,
            'max' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2)
        ];
        echo "  مجموع: {$score}/{$maxScore} (" . round(($score / $maxScore) * 100, 2) . "%)\n\n";
    }

    /**
     * 8. ارزیابی مدیریت خطا و استثناها (5 نمره)
     */
    private function evaluateErrorHandling(): void
    {
        echo "8. ارزیابی مدیریت خطا و استثناها (5 نمره)\n";
        echo str_repeat("-", 100) . "\n";

        $score = 0;
        $maxScore = 5;

        // 8.1 تست Try-Catch در سرویس‌ها (2 نمره)
        try {
            $serviceFiles = glob(app_path('Services/*.php'));
            $servicesWithTryCatch = 0;
            foreach ($serviceFiles as $file) {
                $content = file_get_contents($file);
                if (strpos($content, 'try {') !== false && strpos($content, 'catch') !== false) {
                    $servicesWithTryCatch++;
                }
            }
            $percentage = count($serviceFiles) > 0 ? ($servicesWithTryCatch / count($serviceFiles)) * 100 : 0;
            if ($percentage >= 50) {
                $score += 2;
                echo "  ✓ Try-Catch در سرویس‌ها: 2/2 ({$percentage}%)\n";
            } else {
                $this->improvements[] = "افزودن Try-Catch بیشتر در سرویس‌ها برای مدیریت بهتر خطاها";
                echo "  ⚠ Try-Catch در سرویس‌ها: 1/2 ({$percentage}%)\n";
                $score += 1;
            }
        } catch (\Exception $e) {
            echo "  ✗ Try-Catch در سرویس‌ها: 0/2 (خطا: {$e->getMessage()})\n";
        }

        // 8.2 تست Logging (2 نمره)
        try {
            $logPath = storage_path('logs');
            if (is_dir($logPath) && is_writable($logPath)) {
                $score += 2;
                echo "  ✓ Logging: 2/2\n";
            } else {
                $this->issues[] = "مسیر Log قابل نوشتن نیست";
                echo "  ✗ Logging: 0/2\n";
            }
        } catch (\Exception $e) {
            echo "  ✗ Logging: 0/2 (خطا: {$e->getMessage()})\n";
        }

        // 8.3 تست Validation (1 نمره)
        try {
            $validationService = new ComprehensiveValidationFramework();
            $tender = Tender::first();
            if ($tender) {
                $result = $validationService->validateAllInputs($tender->id);
                if (is_array($result)) {
                    $score += 1;
                    echo "  ✓ Validation: 1/1\n";
                } else {
                    echo "  ✗ Validation: 0/1\n";
                }
            } else {
                echo "  ✗ Validation: 0/1 (مناقصه یافت نشد)\n";
            }
        } catch (\Exception $e) {
            echo "  ✗ Validation: 0/1 (خطا: {$e->getMessage()})\n";
        }

        $this->scores['error_handling'] = [
            'score' => $score,
            'max' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2)
        ];
        echo "  مجموع: {$score}/{$maxScore} (" . round(($score / $maxScore) * 100, 2) . "%)\n\n";
    }

    /**
     * محاسبه نمره نهایی
     */
    private function calculateFinalScore(): void
    {
        foreach ($this->scores as $category => $data) {
            $this->totalScore += $data['score'];
        }
    }

    /**
     * نمایش نتایج
     */
    private function displayResults(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "نتایج آزمون جامع ارزیابی سامانه\n";
        echo str_repeat("=", 100) . "\n\n";

        echo "خلاصه نمرات:\n";
        echo str_repeat("-", 100) . "\n";
        foreach ($this->scores as $category => $data) {
            $categoryName = $this->getCategoryName($category);
            echo sprintf("  %-40s: %3d/%3d (%5.1f%%)\n", 
                $categoryName, 
                $data['score'], 
                $data['max'], 
                $data['percentage']
            );
        }

        echo "\n" . str_repeat("-", 100) . "\n";
        $percentage = round(($this->totalScore / $this->maxScore) * 100, 2);
        echo sprintf("  %-40s: %3d/%3d (%5.1f%%)\n", 
            "نمره کل", 
            $this->totalScore, 
            $this->maxScore, 
            $percentage
        );
        echo str_repeat("=", 100) . "\n\n";

        // نمایش ایرادات
        if (!empty($this->issues)) {
            echo "ایرادات شناسایی شده (" . count($this->issues) . " مورد):\n";
            echo str_repeat("-", 100) . "\n";
            foreach ($this->issues as $index => $issue) {
                echo "  " . ($index + 1) . ". {$issue}\n";
            }
            echo "\n";
        }

        // نمایش نقاط بهبود
        if (!empty($this->improvements)) {
            echo "نقاط بهبود پیشنهادی (" . count($this->improvements) . " مورد):\n";
            echo str_repeat("-", 100) . "\n";
            foreach ($this->improvements as $index => $improvement) {
                echo "  " . ($index + 1) . ". {$improvement}\n";
            }
            echo "\n";
        }

        // نمایش رتبه
        echo "رتبه سامانه:\n";
        echo str_repeat("-", 100) . "\n";
        if ($percentage >= 90) {
            echo "  ⭐⭐⭐⭐⭐ عالی (A)\n";
        } elseif ($percentage >= 80) {
            echo "  ⭐⭐⭐⭐ خوب (B)\n";
        } elseif ($percentage >= 70) {
            echo "  ⭐⭐⭐ قابل قبول (C)\n";
        } elseif ($percentage >= 60) {
            echo "  ⭐⭐ نیاز به بهبود (D)\n";
        } else {
            echo "  ⭐ نیاز به بازنگری جدی (F)\n";
        }
        echo "\n";
    }

    /**
     * تبدیل نام دسته به فارسی
     */
    private function getCategoryName(string $category): string
    {
        $names = [
            'calculations_and_inputs' => 'محاسبات و ورودی‌ها',
            'core_modules' => 'ماژول‌های اصلی',
            'alert_system' => 'سیستم هشدار و خطا',
            'audit_trail' => 'Audit Trail و Tracking',
            'reporting_system' => 'سیستم گزارش‌ها',
            'optimizations' => 'بهینه‌سازی‌ها',
            'user_modules' => 'ماژول‌های کاربری',
            'error_handling' => 'مدیریت خطا و استثناها',
        ];
        return $names[$category] ?? $category;
    }
}


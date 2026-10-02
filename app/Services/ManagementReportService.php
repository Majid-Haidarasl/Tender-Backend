<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Bidder;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\EvaluationResult;
use App\Services\EvaluationService;
use App\Services\ComplianceCheckerService;
use App\Services\AlertNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * سرویس تولید گزارش مدیریتی
 * 
 * این سرویس گزارش خلاصه و رسمی برای مدیریت تولید می‌کند
 */
class ManagementReportService
{
    private $evaluationService;
    private $complianceChecker;
    private $alertService;

    public function __construct()
    {
        $this->evaluationService = new EvaluationService();
        $this->complianceChecker = new ComplianceCheckerService();
        $this->alertService = new AlertNotificationService();
    }

    /**
     * تولید گزارش مدیریتی کامل
     * 
     * @param string $tenderId
     * @param array $options گزینه‌های گزارش
     * @return array
     */
    public function generateManagementReport(string $tenderId, array $options = []): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            throw new \Exception('مناقصه یافت نشد');
        }

        // بررسی و اعتبارسنجی ورودی‌ها
        $validation = $this->validateInputs($tenderId);
        if (!$validation['valid'] && !($options['ignore_validation'] ?? false)) {
            throw new \Exception('ورودی‌ها ناقص یا نامعتبر هستند: ' . implode(', ', $validation['errors']));
        }

        // دریافت نتایج ارزیابی
        $evaluationResult = null;
        try {
            $evaluationResult = $this->evaluationService->getResults($tenderId);
        } catch (\Exception $e) {
            // اگر ارزیابی انجام نشده، ادامه می‌دهیم
            Log::warning("Evaluation not completed for tender {$tenderId}: " . $e->getMessage());
        }

        // بررسی تطابق با دستورالعمل
        $compliance = null;
        if ($evaluationResult) {
            try {
                $compliance = $this->complianceChecker->checkFullCompliance($tenderId);
            } catch (\Exception $e) {
                Log::warning("Compliance check failed: " . $e->getMessage());
            }
        }

        // تولید گزارش
        $report = [
            'tender_info' => $this->getTenderInfo($tender),
            'inputs_summary' => $this->getInputsSummary($tenderId),
            'calculations' => $this->getCalculations($tenderId, $tender, $evaluationResult),
            'decision_path' => $this->getDecisionPath($tenderId, $evaluationResult),
            'statistical_data' => $this->getStatisticalData($tenderId, $evaluationResult),
            'final_results' => $this->getFinalResults($tenderId, $evaluationResult),
            'warnings_and_alerts' => $this->getWarningsAndAlerts($tenderId),
            'management_notes' => $this->getManagementNotes($tenderId, $evaluationResult, $compliance),
            'final_decision' => $this->getFinalDecision($tenderId, $evaluationResult),
            'compliance_status' => $compliance,
            'validation_status' => $validation,
        ];

        return $report;
    }

    /**
     * بررسی و اعتبارسنجی ورودی‌ها
     */
    private function validateInputs(string $tenderId): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'valid' => false,
                'errors' => ['مناقصه یافت نشد'],
                'warnings' => [],
            ];
        }
        $errors = [];
        $warnings = [];

        // بررسی Pb
        $pb = Estimate::calculateTotalPb($tenderId);
        if ($pb <= 0) {
            $errors[] = 'برآورد اولیه (Pb) صفر یا منفی است';
        }

        // بررسی Po
        $po = floatval($tender->po ?? 0);
        if ($po <= 0) {
            $errors[] = 'برآورد به‌هنگام (Po) محاسبه نشده است';
        }

        // بررسی شاخص‌ها
        $indices = Index::getByTenderId($tenderId);
        if ($tender->is_adjustable && empty($indices)) {
            $warnings[] = 'شاخص‌های تعدیل (F) وارد نشده‌اند';
        }

        // بررسی f1-f9 برای روش دوم و سوم
        if (in_array($tender->po_method, ['2', '3'])) {
            $historicalIndices = array_filter($indices, fn($i) => in_array($i['type'], ['f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9']));
            if (count($historicalIndices) < 9) {
                $errors[] = 'داده‌های تاریخی شاخص (f1-f9) ناقص است';
            }
        }

        // بررسی Pi
        $bidders = Bidder::getByTenderId($tenderId);
        if (empty($bidders)) {
            $errors[] = 'هیچ پیشنهادی ثبت نشده است';
        } else {
            foreach ($bidders as $bidder) {
                if (floatval($bidder['price'] ?? 0) <= 0) {
                    $warnings[] = "قیمت پیشنهادی {$bidder['name']} صفر یا منفی است";
                }
            }
        }

        // بررسی امتیاز فنی برای مناقصات دو مرحله‌ای
        if ($tender->is_two_stage) {
            foreach ($bidders as $bidder) {
                $technicalScore = floatval($bidder['technical_score'] ?? 0);
                if ($technicalScore <= 0 || $technicalScore > 100) {
                    $warnings[] = "امتیاز فنی-بازرگانی {$bidder['name']} نامعتبر است";
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
     * دریافت اطلاعات مناقصه
     */
    private function getTenderInfo(Tender $tender): array
    {
        return [
            'code' => $tender->code,
            'title' => $tender->title,
            'type' => $tender->type,
            'date' => $tender->date,
            'status' => $this->translateStatus($tender->status),
            'is_two_stage' => $tender->is_two_stage,
            'is_adjustable' => $tender->is_adjustable,
            'po_method' => $tender->po_method,
        ];
    }

    /**
     * خلاصه ورودی‌ها
     */
    private function getInputsSummary(string $tenderId): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'pb' => ['value' => 0, 'formatted' => '0.00', 'currency' => 'IRR'],
                'po' => ['value' => 0, 'formatted' => '0.00', 'currency' => 'IRR'],
                'adjustment_indices' => [],
                'historical_indices' => [],
                'bidders_count' => 0,
                'bidders' => [],
            ];
        }
        $pb = Estimate::calculateTotalPb($tenderId);
        $po = floatval($tender->po ?? 0);
        $indices = Index::getByTenderId($tenderId);
        $bidders = Bidder::getByTenderId($tenderId);

        // تفکیک شاخص‌های F و f
        $adjustmentIndices = array_filter($indices, fn($i) => in_array($i['type'], ['F1', 'F2', 'F_PRIME_2', 'F_PRIME_3']));
        $historicalIndices = array_filter($indices, fn($i) => in_array($i['type'], ['f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9']));

        return [
            'pb' => [
                'value' => $pb,
                'formatted' => number_format($pb, 2),
                'currency' => 'IRR',
            ],
            'po' => [
                'value' => $po,
                'formatted' => number_format($po, 2),
                'currency' => 'IRR',
            ],
            'adjustment_indices' => array_map(function ($idx) {
                return [
                    'type' => $idx['type'] ?? '',
                    'value' => floatval($idx['value'] ?? 0),
                    'formatted' => number_format($idx['value'] ?? 0, 4),
                ];
            }, $adjustmentIndices),
            'historical_indices' => array_map(function ($idx) {
                return [
                    'type' => $idx['type'] ?? '',
                    'value' => floatval($idx['value'] ?? 0),
                    'formatted' => number_format($idx['value'] ?? 0, 4),
                ];
            }, $historicalIndices),
            'bidders_count' => count($bidders),
            'bidders' => array_map(function ($bidder) {
                return [
                    'name' => $bidder['name'],
                    'price' => floatval($bidder['price'] ?? 0),
                    'formatted_price' => number_format($bidder['price'] ?? 0, 2),
                    'technical_score' => $bidder['technical_score'] ?? null,
                ];
            }, $bidders),
        ];
    }

    /**
     * محاسبات انجام شده
     */
    private function getCalculations(string $tenderId, Tender $tender, ?array $evaluationResult): array
    {
        $calculations = [
            'pb' => Estimate::calculateTotalPb($tenderId),
            'po' => floatval($tender->po ?? 0),
            'po_calculation_method' => $tender->po_method,
        ];

        if ($tender->is_adjustable) {
            $calculations['beta'] = floatval($tender->tbeta ?? 0);
            $calculations['po_formula'] = "Po = Pb × β = " . number_format($calculations['pb'], 2) . " × " . number_format($calculations['beta'], 4);
        } else {
            $calculations['gamma'] = floatval($tender->tgamma ?? 0);
            $calculations['po_formula'] = "Po = Pb × γ = " . number_format($calculations['pb'], 2) . " × " . number_format($calculations['gamma'], 4);
        }

        if ($evaluationResult) {
            $calculations['mean'] = $evaluationResult['mean'] ?? null;
            $calculations['std_dev'] = $evaluationResult['std_dev'] ?? null;
        }

        return $calculations;
    }

    /**
     * مسیر تصمیم‌گیری
     */
    private function getDecisionPath(string $tenderId, ?array $evaluationResult): array
    {
        if (!$evaluationResult) {
            return [
                'path' => null,
                'description' => 'ارزیابی انجام نشده است',
            ];
        }

        $path = $evaluationResult['path'] ?? null;
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'path' => null,
                'description' => 'مناقصه یافت نشد',
            ];
        }
        $po = floatval($tender->po ?? 0);
        $bidders = Bidder::getByTenderId($tenderId);
        $n = count($bidders);

        $pathInfo = [
            'path' => $path,
            'n' => $n,
        ];

        switch ($path) {
            case 'SIMPLE':
                $pathInfo['description'] = 'مسیر ساده (ماده 6-1)';
                $pathInfo['condition'] = "n ≤ 2 یا همه قیمت‌ها در بازه ±10% از Po";
                $pathInfo['action'] = 'تعیین برنده بر اساس کمترین قیمت';
                break;

            case 'STATISTICAL':
                $mean = $evaluationResult['mean'] ?? 0;
                $pathInfo['description'] = 'تحلیل آماری (ماده 6-3)';
                $pathInfo['condition'] = "n ≥ 4 و میانگین در بازه [0.8×Po, 1.35×Po]";
                $pathInfo['mean'] = $mean;
                $pathInfo['mean_range'] = [
                    'lower' => 0.8 * $po,
                    'upper' => 1.35 * $po,
                    'in_range' => $mean >= (0.8 * $po) && $mean <= (1.35 * $po),
                ];
                $pathInfo['action'] = 'نرمال‌سازی آماری و تعیین برنده';
                break;

            case 'SUSPENDED':
                $mean = $evaluationResult['mean'] ?? 0;
                $pathInfo['description'] = 'مسیر وقفه (ماده 6-2)';
                $pathInfo['condition'] = "n ≥ 4 و میانگین خارج از بازه [0.8×Po, 1.35×Po]";
                $pathInfo['mean'] = $mean;
                $pathInfo['mean_range'] = [
                    'lower' => 0.8 * $po,
                    'upper' => 1.35 * $po,
                    'in_range' => false,
                ];
                $pathInfo['action'] = 'ارجاع به کمیته فنی-بازرگانی برای بازنگری';
                break;

            default:
                $pathInfo['description'] = 'مسیر نامشخص';
                $pathInfo['action'] = 'نیاز به بررسی بیشتر';
        }

        return $pathInfo;
    }

    /**
     * داده‌های آماری
     */
    private function getStatisticalData(string $tenderId, ?array $evaluationResult): ?array
    {
        if (!$evaluationResult || ($evaluationResult['path'] ?? '') !== 'STATISTICAL') {
            return null;
        }

        $results = EvaluationResult::getByTenderId($tenderId);
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return null;
        }
        
        $po = floatval($tender->po ?? 0);
        $m_o = $evaluationResult['mean'] ?? 0;
        $s_o = $evaluationResult['std_dev'] ?? 0;

        return [
            'mean' => $m_o,
            'std_dev' => $s_o,
            'normalized_prices' => array_map(function ($result) {
                return [
                    'bidder_name' => $result['bidder_name'] ?? '',
                    'normalized_price' => floatval($result['normalized_price'] ?? 0),
                    'formatted' => number_format($result['normalized_price'] ?? 0, 4),
                    'in_primary_range' => abs($result['normalized_price'] ?? 0) <= 1,
                ];
            }, $results),
            'po_normalized' => $this->calculatePoNormalized($po, $m_o, $s_o),
        ];
    }

    /**
     * محاسبه Po نرمال‌شده
     */
    private function calculatePoNormalized(float $po, float $mean, float $stdDev): ?float
    {
        if ($stdDev <= 0) {
            return null;
        }
        return ($po - $mean) / $stdDev;
    }

    /**
     * نتایج نهایی
     */
    private function getFinalResults(string $tenderId, ?array $evaluationResult): array
    {
        $results = EvaluationResult::getByTenderId($tenderId);
        $tender = Tender::find($tenderId);

        $finalResults = [
            'total_bidders' => count($results),
            'qualified_bidders' => count(array_filter($results, fn($r) => !empty($r))),
            'results' => [],
        ];

        foreach ($results as $result) {
            $finalResults['results'][] = [
                'rank' => $result['rank'] ?? null,
                'bidder_name' => $result['bidder_name'] ?? '',
                'original_price' => floatval($result['bidder_price'] ?? 0),
                'adjusted_price' => floatval($result['adjusted_price'] ?? $result['bidder_price'] ?? 0),
                'normalized_price' => floatval($result['normalized_price'] ?? 0),
                'final_score' => floatval($result['final_score'] ?? 0),
                'is_winner' => ($result['is_winner_first'] ?? false),
                'technical_score' => $result['technical_score'] ?? null,
            ];
        }

        // تعیین برنده
        $winner = array_filter($results, fn($r) => ($r['is_winner_first'] ?? false));
        if (!empty($winner)) {
            $winnerData = reset($winner);
            $finalResults['winner'] = [
                'name' => $winnerData['bidder_name'],
                'price' => floatval($winnerData['bidder_price'] ?? 0),
                'final_score' => floatval($winnerData['final_score'] ?? 0),
            ];
        } else {
            $finalResults['winner'] = null;
            $finalResults['decision'] = 'هیچ پیشنهاد معتبری در دامنه قیمت متناسب وجود ندارد';
        }

        return $finalResults;
    }

    /**
     * هشدارها و اعلان‌ها
     */
    private function getWarningsAndAlerts(string $tenderId): array
    {
        $notifications = \App\Models\Notification::where('tender_id', $tenderId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        $errors = array_filter($notifications, fn($n) => ($n['type'] ?? '') === 'ERROR');
        $warnings = array_filter($notifications, fn($n) => ($n['type'] ?? '') === 'WARNING');
        $info = array_filter($notifications, fn($n) => ($n['type'] ?? '') === 'INFO');

        return [
            'errors' => array_map(function ($error) {
                return [
                    'message_id' => $error['message_id'] ?? '',
                    'message' => $error['message'] ?? '',
                    'stage' => $error['stage'] ?? '',
                    'created_at' => $error['created_at'] ?? null,
                ];
            }, $errors),
            'warnings' => array_map(function ($warning) {
                return [
                    'message_id' => $warning['message_id'] ?? '',
                    'message' => $warning['message'] ?? '',
                    'stage' => $warning['stage'] ?? '',
                    'created_at' => $warning['created_at'] ?? null,
                ];
            }, $warnings),
            'info' => array_map(function ($infoItem) {
                return [
                    'message_id' => $infoItem['message_id'] ?? '',
                    'message' => $infoItem['message'] ?? '',
                    'stage' => $infoItem['stage'] ?? '',
                    'created_at' => $infoItem['created_at'] ?? null,
                ];
            }, $info),
            'summary' => [
                'errors_count' => count($errors),
                'warnings_count' => count($warnings),
                'info_count' => count($info),
            ],
        ];
    }

    /**
     * نکات مدیریتی
     */
    private function getManagementNotes(string $tenderId, ?array $evaluationResult, ?array $compliance): array
    {
        $notes = [];

        // نکات مربوط به مسیر تصمیم
        if ($evaluationResult) {
            $path = $evaluationResult['path'] ?? null;
            switch ($path) {
                case 'SUSPENDED':
                    $notes[] = [
                        'type' => 'warning',
                        'title' => 'نیاز به بازنگری',
                        'description' => 'میانگین قیمت‌ها خارج از بازه مجاز است. نیاز به بررسی مجدد Pb و Po توسط کمیته فنی-بازرگانی.',
                    ];
                    break;

                case 'STATISTICAL':
                    $notes[] = [
                        'type' => 'info',
                        'title' => 'تحلیل آماری انجام شد',
                        'description' => 'مسیر تحلیل آماری انتخاب شده و نرمال‌سازی انجام شده است.',
                    ];
                    break;
            }
        }

        // نکات مربوط به تطابق
        if ($compliance && !$compliance['compliant']) {
            $notes[] = [
                'type' => 'error',
                'title' => 'مغایرت با دستورالعمل',
                'description' => 'مغایرت‌هایی با دستورالعمل شناسایی شده است. لطفاً بررسی کنید.',
                'violations' => $compliance['violations'],
            ];
        }

        // نکات مربوط به مناقصه دو مرحله‌ای
        $tender = Tender::find($tenderId);
        if ($tender && $tender->is_two_stage) {
            $notes[] = [
                'type' => 'info',
                'title' => 'مناقصه دو مرحله‌ای',
                'description' => 'تراز قیمت‌ها بر اساس امتیاز فنی-بازرگانی اعمال شده است.',
            ];
        }

        return $notes;
    }

    /**
     * تصمیم نهایی
     */
    private function getFinalDecision(string $tenderId, ?array $evaluationResult): array
    {
        if (!$evaluationResult) {
            return [
                'status' => 'PENDING',
                'description' => 'ارزیابی انجام نشده است',
            ];
        }

        $results = EvaluationResult::getByTenderId($tenderId);
        $winner = array_filter($results, fn($r) => ($r['is_winner_first'] ?? false));

        if (!empty($winner)) {
            $winnerData = reset($winner);
            if ($winnerData && isset($winnerData['bidder_name'])) {
                return [
                    'status' => 'AWARDED',
                    'description' => 'مناقصه به برنده اهدا شد',
                    'winner' => [
                        'name' => $winnerData['bidder_name'] ?? '',
                        'price' => floatval($winnerData['bidder_price'] ?? 0),
                        'final_score' => floatval($winnerData['final_score'] ?? 0),
                    ],
                ];
            }
        }

        $path = $evaluationResult['path'] ?? null;
        if ($path === 'SUSPENDED') {
            return [
                'status' => 'SUSPENDED',
                'description' => 'مناقصه متوقف شد و به کمیته فنی-بازرگانی ارجاع داده شد',
            ];
        }

        return [
            'status' => 'NO_VALID_BIDS',
            'description' => 'هیچ پیشنهاد معتبری در دامنه قیمت متناسب وجود ندارد',
        ];
    }

    /**
     * ترجمه وضعیت
     */
    private function translateStatus(?string $status): string
    {
        $translations = [
            'DRAFT' => 'پیش‌نویس',
            'PB_APPROVED' => 'Pb تأیید شده',
            'BIDS_OPEN' => 'دریافت پیشنهادات',
            'BIDS_CLOSED' => 'بسته شدن پیشنهادات',
            'PO_CALCULATED' => 'Po محاسبه شده',
            'ANALYSIS_STAT' => 'تحلیل آماری',
            'WINNER_SELECTED' => 'برنده انتخاب شده',
            'SUSPENDED' => 'متوقف شده',
            'WAITING_FOR_INDICES' => 'در انتظار شاخص‌ها',
        ];

        return $translations[$status] ?? $status ?? 'نامشخص';
    }
}


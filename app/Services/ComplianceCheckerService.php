<?php

namespace App\Services;

use App\Models\Tender;
use App\Services\AlertNotificationService;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Log;

/**
 * سرویس مقایسه خودکار با دستورالعمل
 * 
 * این سرویس هر خروجی و مسیر تصمیم‌گیری را با شروط دستورالعمل مقایسه می‌کند
 */
class ComplianceCheckerService
{
    private $alertService;
    private $auditTrailService;

    // شروط دستورالعمل
    private const COMPLIANCE_RULES = [
        // ماده 6-1: مسیر ساده
        'M6_1_SIMPLE' => [
            'condition' => 'n ≤ 2 یا همه در بازه ±10%',
            'check' => 'checkSimplePath',
        ],
        // ماده 6-2: مسیر وقفه
        'M6_2_SUSPENDED' => [
            'condition' => 'n ≥ 4 و m خارج از [0.8×Po, 1.35×Po]',
            'check' => 'checkSuspendedPath',
        ],
        // ماده 6-3: مسیر آماری
        'M6_3_STATISTICAL' => [
            'condition' => 'n ≥ 4 و m در [0.8×Po, 1.35×Po]',
            'check' => 'checkStatisticalPath',
        ],
        // ماده 5: فیلتر اولیه
        'M5_FILTER' => [
            'condition' => 'Pi در محدوده ±3% بخش ارزی',
            'check' => 'checkArticle5Filter',
        ],
        // دامنه اصلی
        'PRIMARY_RANGE' => [
            'condition' => '-1 ≤ P\'i ≤ 1',
            'check' => 'checkPrimaryRange',
        ],
        // دامنه الحاقی
        'ANNEX_RANGE' => [
            'condition' => '±10% یا ±20% از Po',
            'check' => 'checkAnnexRange',
        ],
    ];

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
        $this->auditTrailService = new AuditTrailService();
    }

    /**
     * بررسی تطابق با دستورالعمل
     * 
     * @param string $tenderId
     * @param array $evaluationResult نتیجه ارزیابی
     * @return array ['compliant' => bool, 'violations' => [], 'warnings' => []]
     */
    public function checkCompliance(string $tenderId, array $evaluationResult): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'compliant' => false,
                'violations' => ['مناقصه یافت نشد'],
                'warnings' => [],
            ];
        }

        $violations = [];
        $warnings = [];

        $path = $evaluationResult['path'] ?? null;
        $po = floatval($tender->po ?? 0);
        $bidders = \App\Models\Bidder::getByTenderId($tenderId);
        $n = count($bidders);

        // بررسی مسیر انتخاب شده
        if ($path) {
            $pathCompliance = $this->checkPathCompliance($tenderId, $path, $n, $po, $bidders, $evaluationResult);
            if (!$pathCompliance['compliant']) {
                $violations = array_merge($violations, $pathCompliance['violations']);
                $warnings = array_merge($warnings, $pathCompliance['warnings']);
            }
        }

        // بررسی دامنه قیمت‌ها
        $rangeCompliance = $this->checkRangeCompliance($tenderId, $evaluationResult);
        if (!$rangeCompliance['compliant']) {
            $violations = array_merge($violations, $rangeCompliance['violations']);
            $warnings = array_merge($warnings, $rangeCompliance['warnings']);
        }

        // بررسی مناقصه دو مرحله‌ای
        if ($tender->is_two_stage) {
            $twoStageCompliance = $this->checkTwoStageCompliance($tenderId, $bidders, $evaluationResult);
            if (!$twoStageCompliance['compliant']) {
                $violations = array_merge($violations, $twoStageCompliance['violations']);
                $warnings = array_merge($warnings, $twoStageCompliance['warnings']);
            }
        }

        // ثبت در Audit Trail
        if (!empty($violations)) {
            $this->auditTrailService->logMessage(
                $tenderId,
                'COMPLIANCE_VIOLATION',
                'ERROR',
                'بررسی تطابق',
                $path,
                100,
                true,
                [
                    'violations' => $violations,
                    'warnings' => $warnings,
                ]
            );

            // ثبت خطا در لاگ
            Log::error('Compliance violation detected', [
                'tender_id' => $tenderId,
                'violations' => $violations,
            ]);
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * بررسی تطابق مسیر
     */
    private function checkPathCompliance(string $tenderId, string $path, int $n, float $po, array $bidders, array $evaluationResult): array
    {
        $violations = [];
        $warnings = [];

        $prices = array_filter(array_column($bidders, 'price'), fn($p) => $p > 0);
        $mean = !empty($prices) ? array_sum($prices) / count($prices) : 0;

        switch ($path) {
            case 'SIMPLE':
                // ماده 6-1: n ≤ 2 یا همه در بازه ±10%
                if ($n > 2) {
                    $lowerBound = 0.9 * $po;
                    $upperBound = 1.1 * $po;
                    $allInRange = true;
                    foreach ($prices as $price) {
                        if ($price < $lowerBound || $price > $upperBound) {
                            $allInRange = false;
                            break;
                        }
                    }
                    if (!$allInRange) {
                        $violations[] = 'مغایرت با ماده 6-1: مسیر ساده انتخاب شده اما n > 2 و همه قیمت‌ها در بازه ±10% نیستند';
                    }
                }
                break;

            case 'STATISTICAL':
                // ماده 6-3: n ≥ 4 و m در [0.8×Po, 1.35×Po]
                if ($n < 4) {
                    $violations[] = 'مغایرت با ماده 6-3: مسیر آماری انتخاب شده اما n < 4';
                }
                $lowerBound = 0.8 * $po;
                $upperBound = 1.35 * $po;
                if ($mean < $lowerBound || $mean > $upperBound) {
                    $violations[] = "مغایرت با ماده 6-3: مسیر آماری انتخاب شده اما میانگین ({$mean}) خارج از بازه [{$lowerBound}, {$upperBound}] است";
                }
                break;

            case 'SUSPENDED':
                // ماده 6-2: n ≥ 4 و m خارج از [0.8×Po, 1.35×Po]
                if ($n < 4) {
                    $violations[] = 'مغایرت با ماده 6-2: مسیر وقفه انتخاب شده اما n < 4';
                }
                $lowerBound = 0.8 * $po;
                $upperBound = 1.35 * $po;
                if ($mean >= $lowerBound && $mean <= $upperBound) {
                    $violations[] = "مغایرت با ماده 6-2: مسیر وقفه انتخاب شده اما میانگین ({$mean}) در بازه [{$lowerBound}, {$upperBound}] است";
                }
                break;
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * بررسی تطابق دامنه قیمت‌ها
     */
    private function checkRangeCompliance(string $tenderId, array $evaluationResult): array
    {
        $violations = [];
        $warnings = [];

        $results = $evaluationResult['results'] ?? [];
        $ranges = $evaluationResult['ranges'] ?? [];

        // بررسی دامنه اصلی: -1 ≤ P'i ≤ 1
        foreach ($results as $result) {
            $normalized = $result['statistically_normalized'] ?? null;
            if ($normalized !== null) {
                if ($normalized < -1 || $normalized > 1) {
                    // بررسی دامنه الحاقی
                    $inAnnexRange = $result['in_annex_range'] ?? false;
                    if (!$inAnnexRange) {
                        $violations[] = "مغایرت با دامنه اصلی: P'i ({$normalized}) خارج از [-1, 1] و در دامنه الحاقی نیست";
                    }
                }
            }
        }

        // بررسی دامنه الحاقی
        $poInPrimaryRange = $ranges['Po_in_primary_range'] ?? false;
        $annexPercent = $poInPrimaryRange ? 0.20 : 0.10;
        $po = floatval($evaluationResult['Po'] ?? 0);
        $annexLower = $po * (1 - $annexPercent);
        $annexUpper = $po * (1 + $annexPercent);

        foreach ($results as $result) {
            $price = $result['adjusted_price'] ?? $result['original_price'] ?? 0;
            $inAnnexRange = ($price >= $annexLower && $price <= $annexUpper);
            $inPrimaryRange = ($result['in_primary_range'] ?? false);

            if (!$inPrimaryRange && !$inAnnexRange) {
                $violations[] = "مغایرت با دامنه الحاقی: قیمت {$price} خارج از دامنه الحاقی ±" . ($annexPercent * 100) . "% است";
            }
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * بررسی تطابق مناقصه دو مرحله‌ای
     */
    private function checkTwoStageCompliance(string $tenderId, array $bidders, array $evaluationResult): array
    {
        $violations = [];
        $warnings = [];

        // بررسی وجود امتیاز فنی
        $hasTechnicalScores = false;
        foreach ($bidders as $bidder) {
            $technicalScore = floatval($bidder['technical_score'] ?? 0);
            if ($technicalScore > 0) {
                $hasTechnicalScores = true;
                break;
            }
        }

        if (!$hasTechnicalScores) {
            $violations[] = 'مغایرت با دستورالعمل: مناقصه دو مرحله‌ای است اما امتیاز فنی-بازرگانی ثبت نشده است';
        }

        // بررسی اعمال تراز
        $results = $evaluationResult['results'] ?? [];
        $adjustmentApplied = false;
        foreach ($results as $result) {
            if (isset($result['adjusted_price']) && $result['adjusted_price'] !== $result['original_price']) {
                $adjustmentApplied = true;
                break;
            }
        }

        if (!$adjustmentApplied) {
            $violations[] = 'مغایرت با دستورالعمل: مناقصه دو مرحله‌ای است اما تراز قیمت‌ها اعمال نشده است';
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * بررسی تطابق کامل یک مناقصه
     */
    public function checkFullCompliance(string $tenderId): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return [
                'compliant' => false,
                'violations' => ['مناقصه یافت نشد'],
                'warnings' => [],
            ];
        }

        $allViolations = [];
        $allWarnings = [];

        // بررسی ارزیابی (اگر انجام شده)
        $hasEvaluationCalculatedAt = \Illuminate\Support\Facades\Schema::hasColumn('tenders', 'evaluation_calculated_at');
        if ($hasEvaluationCalculatedAt && $tender->evaluation_calculated_at) {
            $evaluationService = new EvaluationService();
            try {
                $evaluationResult = $evaluationService->getResults($tenderId);
                $compliance = $this->checkCompliance($tenderId, $evaluationResult);
                $allViolations = array_merge($allViolations, $compliance['violations']);
                $allWarnings = array_merge($allWarnings, $compliance['warnings']);
            } catch (\Exception $e) {
                $allViolations[] = 'خطا در بررسی تطابق ارزیابی: ' . $e->getMessage();
            }
        }

        return [
            'compliant' => empty($allViolations),
            'violations' => $allViolations,
            'warnings' => $allWarnings,
        ];
    }
}


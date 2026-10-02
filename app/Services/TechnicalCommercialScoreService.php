<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Bidder;
use App\Services\AlertNotificationService;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Log;

/**
 * سرویس محاسبه و اعمال تراز و امتیاز فنی-بازرگانی
 * برای مناقصات دو مرحله‌ای
 */
class TechnicalCommercialScoreService
{
    private $alertService;
    private $auditTrailService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
        $this->auditTrailService = new AuditTrailService();
    }

    /**
     * محاسبه و اعمال تراز قیمت‌ها بر اساس امتیاز فنی-بازرگانی
     * 
     * فرمول: P'_i = P_i × (A_max / A_i)
     * 
     * @param string $tenderId
     * @param array $bidders آرایه مناقصه‌گران
     * @param float $aMax حداکثر امتیاز (معمولاً 100)
     * @return array ['adjusted_prices' => [], 'warnings' => [], 'errors' => []]
     */
    public function calculateAdjustedPrices(string $tenderId, array $bidders, float $aMax = 100): array
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            throw new \Exception('مناقصه یافت نشد');
        }

        if (!$tender->is_two_stage) {
            // اگر مناقصه دو مرحله‌ای نیست، قیمت‌ها بدون تغییر برمی‌گردند
            return [
                'adjusted_prices' => array_map(function ($bidder) {
                    return [
                        'bidder_id' => $bidder['id'],
                        'bidder_name' => $bidder['name'],
                        'original_price' => floatval($bidder['price'] ?? 0),
                        'adjusted_price' => floatval($bidder['price'] ?? 0),
                        'technical_score' => floatval($bidder['technical_score'] ?? 0),
                        'adjustment_applied' => false,
                    ];
                }, $bidders),
                'warnings' => [],
                'errors' => [],
            ];
        }

        $errors = [];
        $warnings = [];
        $adjustedPrices = [];

        // اعتبارسنجی داده‌های فنی
        $validation = $this->validateTechnicalScores($tenderId, $bidders, $aMax);
        if (!$validation['valid']) {
            $errors = array_merge($errors, $validation['errors']);
            $warnings = array_merge($warnings, $validation['warnings']);
        }

        // اگر خطای مانع وجود دارد، متوقف می‌شود
        if (!empty($errors)) {
            $this->alertService->sendError('E006', $tenderId, [
                'is_two_stage' => true,
                'technical_stage_complete' => false,
                'errors' => $errors,
            ]);
            throw new \Exception('داده‌های فنی ناقص یا ناسازگار هستند: ' . implode(', ', $errors));
        }

        // محاسبه تراز برای هر مناقصه‌گر
        foreach ($bidders as $bidder) {
            $originalPrice = floatval($bidder['price'] ?? 0);
            $technicalScore = floatval($bidder['technical_score'] ?? 0);

            if ($originalPrice <= 0) {
                $warnings[] = "قیمت پیشنهاد {$bidder['name']} نامعتبر است";
                continue;
            }

            if ($technicalScore <= 0 || $technicalScore > $aMax) {
                $warnings[] = "امتیاز فنی {$bidder['name']} نامعتبر است";
                continue;
            }

            // محاسبه قیمت تراز شده: P'_i = P_i × (A_max / A_i)
            $adjustedPrice = $originalPrice * ($aMax / $technicalScore);

            $adjustedPrices[] = [
                'bidder_id' => $bidder['id'],
                'bidder_name' => $bidder['name'],
                'original_price' => $originalPrice,
                'adjusted_price' => $adjustedPrice,
                'technical_score' => $technicalScore,
                'adjustment_factor' => $aMax / $technicalScore,
                'adjustment_applied' => true,
            ];

            // ثبت در Audit Trail
            $this->auditTrailService->logCalculation(
                $tenderId,
                'PRICE_ADJUSTMENT',
                [
                    'bidder_id' => $bidder['id'],
                    'original_price' => $originalPrice,
                    'technical_score' => $technicalScore,
                    'a_max' => $aMax,
                ],
                [
                    'adjusted_price' => $adjustedPrice,
                    'adjustment_factor' => $aMax / $technicalScore,
                ],
                AlertNotificationService::STAGE_TECHNICAL_COMMERCIAL,
                "P'_i = P_i × (A_max / A_i) = {$originalPrice} × ({$aMax} / {$technicalScore})"
            );
        }

        // ثبت تصمیم تراز
        $this->auditTrailService->logDecision(
            $tenderId,
            \App\Models\DecisionHistory::DECISION_PRICE_ADJUSTMENT,
            'STATISTICAL',
            AlertNotificationService::STAGE_TECHNICAL_COMMERCIAL,
            'تراز قیمت‌ها بر اساس امتیاز فنی-بازرگانی اعمال شد',
            [
                'bidders_count' => count($bidders),
                'a_max' => $aMax,
            ],
            [
                'adjusted_prices' => $adjustedPrices,
            ],
            [
                'adjustment_applied' => true,
                'bidders_count' => count($adjustedPrices),
            ],
            [],
            [],
            50,
            false
        );

        return [
            'adjusted_prices' => $adjustedPrices,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * اعتبارسنجی امتیازهای فنی-بازرگانی
     */
    private function validateTechnicalScores(string $tenderId, array $bidders, float $aMax): array
    {
        $errors = [];
        $warnings = [];

        $hasTechnicalScores = false;
        $invalidScores = [];

        foreach ($bidders as $bidder) {
            $technicalScore = floatval($bidder['technical_score'] ?? 0);
            
            if ($technicalScore > 0) {
                $hasTechnicalScores = true;
            }

            // بررسی اعتبار امتیاز
            if ($technicalScore <= 0 || $technicalScore > $aMax) {
                $invalidScores[] = [
                    'bidder' => $bidder['name'] ?? 'نامشخص',
                    'score' => $technicalScore,
                ];
            }
        }

        // بررسی وجود امتیاز فنی
        if (!$hasTechnicalScores) {
            $errors[] = 'امتیاز فنی-بازرگانی برای هیچ مناقصه‌گری ثبت نشده است';
            $this->alertService->sendError('E006', $tenderId, [
                'is_two_stage' => true,
                'technical_stage_complete' => false,
            ]);
        }

        // بررسی امتیازهای نامعتبر
        if (!empty($invalidScores)) {
            foreach ($invalidScores as $invalid) {
                $warnings[] = "امتیاز فنی {$invalid['bidder']} نامعتبر است: {$invalid['score']} (باید بین 0 و {$aMax} باشد)";
            }
            $this->alertService->sendWarning('W009', $tenderId, [
                'invalid_scores' => $invalidScores,
                'a_max' => $aMax,
            ]);
        }

        // بررسی ناسازگاری (مثلاً همه امتیازها یکسان باشند)
        $scores = array_filter(array_column($bidders, 'technical_score'), fn($s) => $s > 0);
        if (count($scores) > 1) {
            $uniqueScores = array_unique($scores);
            if (count($uniqueScores) === 1) {
                $warnings[] = 'تمام امتیازهای فنی یکسان هستند. بررسی صحت امتیازها لازم است';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * محاسبه امتیاز نهایی ترکیبی (فنی + بازرگانی)
     * 
     * در صورت نیاز به ترکیب امتیاز فنی و قیمت
     */
    public function calculateCombinedScore(array $bidderData, float $technicalWeight = 0.3, float $commercialWeight = 0.7): float
    {
        $technicalScore = floatval($bidderData['technical_score'] ?? 0);
        $price = floatval($bidderData['price'] ?? 0);
        $maxPrice = floatval($bidderData['max_price'] ?? 0);

        if ($maxPrice <= 0 || $price <= 0) {
            return 0;
        }

        // نرمال‌سازی قیمت (کمتر = بهتر)
        $commercialScore = (1 - ($price / $maxPrice)) * 100;

        // ترکیب امتیازها
        $combinedScore = ($technicalScore * $technicalWeight) + ($commercialScore * $commercialWeight);

        return $combinedScore;
    }
}


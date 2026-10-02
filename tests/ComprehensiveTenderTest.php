<?php

/**
 * اسکریپت تست جامع برای تمام حالت‌های ممکن محاسبات مناقصات
 * 
 * این اسکریپت تمام حالت‌های ممکن را پوشش می‌دهد:
 * - is_two_stage: true / false
 * - is_adjustable: true / false
 * - po_method: '1', '2', '3'
 * - normalize_prices: true / false
 * 
 * در مجموع: 2 × 2 × 3 × 2 = 24 حالت مختلف
 * 
 * برای هر حالت:
 * 1. ایجاد مناقصه تست
 * 2. اضافه کردن برآورد اولیه با بخش‌های مختلف
 * 3. اضافه کردن شاخص‌های مورد نیاز
 * 4. محاسبه Po
 * 5. اضافه کردن پیشنهاددهندگان
 * 6. انجام ارزیابی
 * 7. انتخاب برنده اول و دوم
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Models\BidderPriceItem;
use App\Models\EvaluationResult;
use App\Services\PoCalculationService;
use App\Services\EvaluationService;
use Illuminate\Support\Facades\DB;

// بارگذاری Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class ComprehensiveTenderTest
{
    private $poService;
    private $evaluationService;
    private $testResults = [];
    private $testCounter = 0;

    public function __construct()
    {
        $this->poService = new PoCalculationService();
        $this->evaluationService = new EvaluationService();
    }

    /**
     * اجرای تمام تست‌ها
     */
    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "شروع تست جامع تمام حالت‌های ممکن محاسبات مناقصات\n";
        echo str_repeat("=", 80) . "\n\n";

        $testCases = $this->generateTestCases();

        foreach ($testCases as $index => $testCase) {
            $this->testCounter = $index + 1;
            echo "\n" . str_repeat("-", 80) . "\n";
            echo "تست {$this->testCounter}/24: {$testCase['name']}\n";
            echo str_repeat("-", 80) . "\n";

            try {
                $result = $this->runSingleTest($testCase);
                $this->testResults[] = [
                    'test_number' => $this->testCounter,
                    'name' => $testCase['name'],
                    'status' => 'SUCCESS',
                    'tender_id' => $result['tender_id'],
                    'tender_code' => $result['tender_code'],
                    'po' => $result['po'],
                    'pb' => $result['pb'],
                    'evaluation_status' => $result['evaluation_status'],
                    'winners' => $result['winners'],
                    'error' => null,
                ];
                echo "✓ تست با موفقیت انجام شد\n";
                echo "  کد مناقصه: {$result['tender_code']}\n";
                echo "  Pb: " . number_format($result['pb'], 2) . " ریال\n";
                echo "  Po: " . number_format($result['po'], 2) . " ریال\n";
                echo "  وضعیت ارزیابی: {$result['evaluation_status']}\n";
                if (!empty($result['winners'])) {
                    echo "  برنده اول: {$result['winners']['first']}\n";
                    if (!empty($result['winners']['second'])) {
                        echo "  برنده دوم: {$result['winners']['second']}\n";
                    }
                }
            } catch (\Exception $e) {
                $this->testResults[] = [
                    'test_number' => $this->testCounter,
                    'name' => $testCase['name'],
                    'status' => 'FAILED',
                    'error' => $e->getMessage(),
                ];
                echo "✗ تست با خطا مواجه شد: {$e->getMessage()}\n";
                echo "  Trace: " . $e->getTraceAsString() . "\n";
            }
        }

        $this->printSummary();
    }

    /**
     * تولید تمام حالت‌های تست
     */
    private function generateTestCases(): array
    {
        $testCases = [];
        $counter = 0;

        $twoStageOptions = [false, true];
        $adjustableOptions = [false, true];
        $poMethods = ['1', '2', '3'];
        $normalizeOptions = [false, true];

        foreach ($twoStageOptions as $isTwoStage) {
            foreach ($adjustableOptions as $isAdjustable) {
                foreach ($poMethods as $poMethod) {
                    foreach ($normalizeOptions as $normalizePrices) {
                        $counter++;
                        $name = sprintf(
                            "مناقصه %s - %s - روش Po %s - %s",
                            $isTwoStage ? 'دو مرحله‌ای' : 'یک مرحله‌ای',
                            $isAdjustable ? 'قابل تعدیل' : 'غیر قابل تعدیل',
                            $poMethod,
                            $normalizePrices ? 'با نرمال‌سازی' : 'بدون نرمال‌سازی'
                        );

                        $testCases[] = [
                            'name' => $name,
                            'is_two_stage' => $isTwoStage,
                            'is_adjustable' => $isAdjustable,
                            'po_method' => $poMethod,
                            'normalize_prices' => $normalizePrices,
                        ];
                    }
                }
            }
        }

        return $testCases;
    }

    /**
     * اجرای یک تست
     */
    private function runSingleTest(array $testCase): array
    {
        DB::beginTransaction();

        try {
            // 1. ایجاد مناقصه
            $tender = $this->createTender($testCase);
            echo "  ✓ مناقصه ایجاد شد: {$tender->code}\n";

            // 2. اضافه کردن برآورد اولیه
            $this->createEstimates($tender->id);
            $pb = Estimate::calculateTotalPb($tender->id);
            echo "  ✓ برآورد اولیه ایجاد شد: Pb = " . number_format($pb, 2) . " ریال\n";

            // 3. اضافه کردن شاخص‌ها
            $this->createIndices($tender->id, $testCase['po_method'], $testCase['is_adjustable']);
            echo "  ✓ شاخص‌ها ایجاد شدند\n";

            // 4. محاسبه Po
            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            
            // به‌روزرسانی مناقصه با Po
            $tender->update([
                'pb' => $pb,
                'po' => $po,
            ]);
            Tender::setPoCalculatedAt($tender->id);
            echo "  ✓ Po محاسبه شد: " . number_format($po, 2) . " ریال\n";

            // 5. اضافه کردن پیشنهاددهندگان
            $bidders = $this->createBidders($tender->id, $po);
            echo "  ✓ {$bidders['count']} پیشنهاددهنده ایجاد شد\n";

            // 6. انجام ارزیابی
            $evaluationResult = $this->evaluationService->evaluate($tender->id);
            $evaluationStatus = $evaluationResult['action'] ?? 'COMPLETED';
            echo "  ✓ ارزیابی انجام شد: {$evaluationStatus}\n";

            // 7. انتخاب برنده اول و دوم
            $winners = $this->selectWinners($tender->id);
            if (!empty($winners)) {
                echo "  ✓ برنده اول: {$winners['first']}\n";
                if (!empty($winners['second'])) {
                    echo "  ✓ برنده دوم: {$winners['second']}\n";
                }
            }

            // 8. بررسی اعداد منفی
            $this->checkForNegativeNumbers($tender->id, $pb, $po);

            DB::commit();

            return [
                'tender_id' => $tender->id,
                'tender_code' => $tender->code,
                'pb' => $pb,
                'po' => $po,
                'evaluation_status' => $evaluationStatus,
                'winners' => $winners,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * ایجاد مناقصه
     */
    private function createTender(array $testCase): Tender
    {
        $timestamp = time();
        $code = sprintf(
            'TEST-%02d-%s-%s-PO%s-%s-%d',
            $this->testCounter,
            $testCase['is_two_stage'] ? '2STG' : '1STG',
            $testCase['is_adjustable'] ? 'ADJ' : 'NADJ',
            $testCase['po_method'],
            $testCase['normalize_prices'] ? 'NORM' : 'NO-NORM',
            $timestamp
        );

        return Tender::create([
            'code' => $code,
            'title' => $testCase['name'],
            'type' => 'test',
            'date' => '1403/09/15',
            'is_two_stage' => $testCase['is_two_stage'],
            'normalize_prices' => $testCase['normalize_prices'],
            'is_adjustable' => $testCase['is_adjustable'],
            'base_period' => '1403/01/01',
            'po_method' => $testCase['po_method'],
            'tgamma' => 1.0,
            'tbeta' => 0.5,
            'delta' => 0,
            'a_max' => $testCase['normalize_prices'] ? 100 : null,
            'normalization_factor' => $testCase['normalize_prices'] ? 1.0 : null,
            'description' => 'مناقصه تست - ' . $testCase['name'],
            'status' => 'active',
        ]);
    }

    /**
     * ایجاد برآورد اولیه
     */
    private function createEstimates(string $tenderId): void
    {
        $estimates = [
            [
                'section' => 'بخش اول - فهرست بهای پایه',
                'amount' => 1000000000, // 1 میلیارد ریال
                'currency' => 'IRR',
                'exchange_rate' => null,
                'amount_in_rials' => 1000000000,
                'is_adjustable' => true,
                'calculation_method' => 'base_price_list',
                'base_period' => '1403/01/01',
                'notes' => 'بخش قابل تعدیل از فهرست بهای پایه',
            ],
            [
                'section' => 'بخش دوم - منشا خارجی',
                'amount' => 500000, // 500 هزار دلار
                'currency' => 'USD',
                'exchange_rate' => 50000, // 50 هزار ریال
                'amount_in_rials' => 25000000000, // 25 میلیارد ریال
                'is_adjustable' => false,
                'calculation_method' => 'foreign_source',
                'base_period' => '1403/02/01',
                'notes' => 'بخش غیر قابل تعدیل از منشا خارجی',
            ],
            [
                'section' => 'بخش سوم - سایر ارز',
                'amount' => 100000, // 100 هزار یورو
                'currency' => 'OTHER',
                'currency_name' => 'یورو',
                'exchange_rate' => 55000, // 55 هزار ریال
                'amount_in_rials' => 5500000000, // 5.5 میلیارد ریال
                'is_adjustable' => true,
                'calculation_method' => 'foreign_source',
                'base_period' => '1403/03/01',
                'notes' => 'بخش قابل تعدیل با ارز دیگر',
            ],
        ];

        foreach ($estimates as $estimateData) {
            Estimate::create(array_merge($estimateData, ['tender_id' => $tenderId]));
        }
    }

    /**
     * ایجاد شاخص‌ها بر اساس روش محاسبه Po
     */
    private function createIndices(string $tenderId, string $poMethod, bool $isAdjustable): void
    {
        $indices = [];

        if ($poMethod === '1') {
            // روش اول: نیاز به I1, I2, I3, r1, r2
            $indices = [
                ['type' => 'I1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص I1'],
                ['type' => 'I2', 'value' => 110.0, 'date' => '1403/06/01', 'description' => 'شاخص I2'],
                ['type' => 'I3', 'value' => 115.0, 'date' => '1403/09/01', 'description' => 'شاخص I3'],
                ['type' => 'r1', 'value' => 0.05, 'date' => null, 'description' => 'نرخ رشد r1'],
                ['type' => 'r2', 'value' => 0.03, 'date' => null, 'description' => 'نرخ رشد r2'],
            ];
        } elseif ($poMethod === '2') {
            // روش دوم: نیاز به F1, F2 (یا f1-f9), z
            $indices = [
                ['type' => 'F1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص F1'],
                ['type' => 'F2', 'value' => 120.0, 'date' => '1403/09/01', 'description' => 'شاخص F2'],
                ['type' => 'z', 'value' => 2.0, 'date' => null, 'description' => 'پارامتر z'],
                // همچنین f1-f9 برای محاسبه F'2 در صورت نیاز
                ['type' => 'f1', 'value' => 100.0, 'date' => '1402/12/01', 'description' => 'شاخص f1'],
                ['type' => 'f2', 'value' => 102.0, 'date' => '1403/01/01', 'description' => 'شاخص f2'],
                ['type' => 'f3', 'value' => 104.0, 'date' => '1403/02/01', 'description' => 'شاخص f3'],
                ['type' => 'f4', 'value' => 106.0, 'date' => '1403/03/01', 'description' => 'شاخص f4'],
                ['type' => 'f5', 'value' => 108.0, 'date' => '1403/04/01', 'description' => 'شاخص f5'],
                ['type' => 'f6', 'value' => 110.0, 'date' => '1403/05/01', 'description' => 'شاخص f6'],
                ['type' => 'f7', 'value' => 112.0, 'date' => '1403/06/01', 'description' => 'شاخص f7'],
                ['type' => 'f8', 'value' => 114.0, 'date' => '1403/07/01', 'description' => 'شاخص f8'],
                ['type' => 'f9', 'value' => 116.0, 'date' => '1403/08/01', 'description' => 'شاخص f9'],
            ];
        } elseif ($poMethod === '3') {
            // روش سوم: نیاز به I1, I2, I3, F1, F2 (یا f1-f9), z
            $indices = [
                ['type' => 'I1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص I1'],
                ['type' => 'I2', 'value' => 110.0, 'date' => '1403/06/01', 'description' => 'شاخص I2'],
                ['type' => 'I3', 'value' => 115.0, 'date' => '1403/09/01', 'description' => 'شاخص I3'],
                ['type' => 'F1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص F1'],
                ['type' => 'F2', 'value' => 120.0, 'date' => '1403/09/01', 'description' => 'شاخص F2'],
                ['type' => 'z', 'value' => 2.0, 'date' => null, 'description' => 'پارامتر z'],
                // همچنین f1-f9 برای محاسبه F'2 در صورت نیاز
                ['type' => 'f1', 'value' => 100.0, 'date' => '1402/12/01', 'description' => 'شاخص f1'],
                ['type' => 'f2', 'value' => 102.0, 'date' => '1403/01/01', 'description' => 'شاخص f2'],
                ['type' => 'f3', 'value' => 104.0, 'date' => '1403/02/01', 'description' => 'شاخص f3'],
                ['type' => 'f4', 'value' => 106.0, 'date' => '1403/03/01', 'description' => 'شاخص f4'],
                ['type' => 'f5', 'value' => 108.0, 'date' => '1403/04/01', 'description' => 'شاخص f5'],
                ['type' => 'f6', 'value' => 110.0, 'date' => '1403/05/01', 'description' => 'شاخص f6'],
                ['type' => 'f7', 'value' => 112.0, 'date' => '1403/06/01', 'description' => 'شاخص f7'],
                ['type' => 'f8', 'value' => 114.0, 'date' => '1403/07/01', 'description' => 'شاخص f8'],
                ['type' => 'f9', 'value' => 116.0, 'date' => '1403/08/01', 'description' => 'شاخص f9'],
            ];
        }

        foreach ($indices as $indexData) {
            Index::createOrUpdate(array_merge($indexData, ['tender_id' => $tenderId]));
        }
    }

    /**
     * ایجاد پیشنهاددهندگان
     */
    private function createBidders(string $tenderId, float $po): array
    {
        // ایجاد 5 پیشنهاددهنده با قیمت‌های مختلف نسبت به Po
        $bidders = [
            ['name' => 'پیشنهاددهنده 1', 'price' => $po * 0.85, 'technical_score' => 95.0], // 85% Po
            ['name' => 'پیشنهاددهنده 2', 'price' => $po * 0.92, 'technical_score' => 90.0], // 92% Po
            ['name' => 'پیشنهاددهنده 3', 'price' => $po * 1.05, 'technical_score' => 85.0], // 105% Po
            ['name' => 'پیشنهاددهنده 4', 'price' => $po * 1.15, 'technical_score' => 80.0], // 115% Po
            ['name' => 'پیشنهاددهنده 5', 'price' => $po * 0.88, 'technical_score' => 88.0], // 88% Po
        ];

        $createdBidders = [];
        foreach ($bidders as $bidderData) {
            $bidder = Bidder::create([
                'tender_id' => $tenderId,
                'name' => $bidderData['name'],
                'price' => $bidderData['price'],
                'technical_score' => $bidderData['technical_score'],
                'notes' => 'پیشنهاددهنده تست',
            ]);

            // اضافه کردن یک آیتم قیمت برای هر پیشنهاددهنده
            BidderPriceItem::create([
                'bidder_id' => $bidder->id,
                'amount' => $bidderData['price'],
                'currency' => 'IRR',
                'currency_name' => null,
                'exchange_rate' => null,
                'amount_in_rials' => $bidderData['price'],
                'is_adjustable' => true,
                'description' => 'قیمت کل پیشنهادی',
            ]);

            $createdBidders[] = $bidder;
        }

        return [
            'count' => count($createdBidders),
            'bidders' => $createdBidders,
        ];
    }

    /**
     * انتخاب برنده اول و دوم
     */
    private function selectWinners(string $tenderId): array
    {
        // دریافت نتایج کامل ارزیابی از سرویس
        $evaluationData = $this->evaluationService->getResults($tenderId);
        $evaluations = $evaluationData['results'] ?? [];

        if (empty($evaluations)) {
            return [];
        }

        // دریافت محدوده قیمت از نتایج ارزیابی
        $tender = Tender::find($tenderId);
        $po = floatval($tender->po ?? 0);
        
        // محاسبه محدوده قیمت (از EvaluationService استفاده می‌کنیم)
        // برای سادگی، از محدوده 0.9 * Po تا 1.1 * Po استفاده می‌کنیم
        $pLower = $po * 0.9;
        $pUpper = $po * 1.1;

        // مرتب‌سازی بر اساس final_score (کمترین بهترین)
        usort($evaluations, function ($a, $b) {
            $scoreA = floatval($a['final_score'] ?? PHP_FLOAT_MAX);
            $scoreB = floatval($b['final_score'] ?? PHP_FLOAT_MAX);
            return $scoreA <=> $scoreB;
        });

        // پیدا کردن برنده اول (کمترین final_score که در محدوده است)
        $winnerFirst = null;
        $winnerSecond = null;

        foreach ($evaluations as $eval) {
            $normalizedPrice = floatval($eval['normalized_price'] ?? 0);
            $inRange = $normalizedPrice >= $pLower && $normalizedPrice <= $pUpper;

            if ($inRange && $winnerFirst === null) {
                $winnerFirst = $eval;
            } elseif ($inRange && $winnerSecond === null && $eval['id'] !== ($winnerFirst['id'] ?? null)) {
                $winnerSecond = $eval;
                break;
            }
        }

        // اگر برنده اول پیدا نشد، اولین پیشنهاددهنده را انتخاب کن
        if ($winnerFirst === null && !empty($evaluations)) {
            $winnerFirst = $evaluations[0];
        }

        // اگر برنده دوم پیدا نشد، دومین پیشنهاددهنده را انتخاب کن
        if ($winnerSecond === null && count($evaluations) > 1 && $evaluations[1]['id'] !== ($winnerFirst['id'] ?? null)) {
            $winnerSecond = $evaluations[1];
        }

        // به‌روزرسانی برنده‌ها در دیتابیس
        if ($winnerFirst) {
            EvaluationResult::where('id', $winnerFirst['id'])->update([
                'is_winner' => true,
                'is_winner_first' => true,
            ]);
        }

        if ($winnerSecond) {
            EvaluationResult::where('id', $winnerSecond['id'])->update([
                'is_winner_second' => true,
            ]);
        }

        return [
            'first' => $winnerFirst ? $winnerFirst['bidder_name'] : null,
            'second' => $winnerSecond ? $winnerSecond['bidder_name'] : null,
        ];
    }

    /**
     * بررسی اعداد منفی
     */
    private function checkForNegativeNumbers(string $tenderId, float $pb, float $po): void
    {
        // بررسی Pb
        if ($pb < 0) {
            throw new \Exception("خطا: Pb منفی است: {$pb}");
        }

        // بررسی Po
        if ($po < 0) {
            throw new \Exception("خطا: Po منفی است: {$po}");
        }

        // بررسی برآوردها
        $estimates = Estimate::getByTenderId($tenderId);
        foreach ($estimates as $estimate) {
            if (floatval($estimate['amount']) < 0) {
                throw new \Exception("خطا: مبلغ برآورد منفی است: {$estimate['section']} = {$estimate['amount']}");
            }
            if (floatval($estimate['amount_in_rials']) < 0) {
                throw new \Exception("خطا: مبلغ به ریال منفی است: {$estimate['section']} = {$estimate['amount_in_rials']}");
            }
            if (isset($estimate['exchange_rate']) && $estimate['exchange_rate'] !== null && floatval($estimate['exchange_rate']) < 0) {
                throw new \Exception("خطا: نرخ تسعیر منفی است: {$estimate['section']} = {$estimate['exchange_rate']}");
            }
        }

        // بررسی پیشنهاددهندگان
        $bidders = Bidder::getByTenderId($tenderId);
        foreach ($bidders as $bidder) {
            if (floatval($bidder['price']) < 0) {
                throw new \Exception("خطا: قیمت پیشنهادی منفی است: {$bidder['name']} = {$bidder['price']}");
            }
        }

        // بررسی نتایج ارزیابی
        $evaluations = EvaluationResult::getByTenderId($tenderId);
        foreach ($evaluations as $eval) {
            if (isset($eval['normalized_price']) && floatval($eval['normalized_price']) < 0) {
                throw new \Exception("خطا: قیمت نرمال‌شده منفی است: {$eval['bidder_name']} = {$eval['normalized_price']}");
            }
            if (isset($eval['final_score']) && floatval($eval['final_score']) < 0) {
                throw new \Exception("خطا: امتیاز نهایی منفی است: {$eval['bidder_name']} = {$eval['final_score']}");
            }
        }

        echo "  ✓ بررسی اعداد منفی: هیچ عدد منفی یافت نشد\n";
    }

    /**
     * چاپ خلاصه نتایج
     */
    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "خلاصه نتایج تست\n";
        echo str_repeat("=", 80) . "\n\n";

        $successCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'SUCCESS'));
        $failedCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'FAILED'));

        echo "تعداد کل تست‌ها: " . count($this->testResults) . "\n";
        echo "تست‌های موفق: {$successCount}\n";
        echo "تست‌های ناموفق: {$failedCount}\n\n";

        if ($failedCount > 0) {
            echo "تست‌های ناموفق:\n";
            echo str_repeat("-", 80) . "\n";
            foreach ($this->testResults as $result) {
                if ($result['status'] === 'FAILED') {
                    echo "  تست {$result['test_number']}: {$result['name']}\n";
                    echo "    خطا: {$result['error']}\n\n";
                }
            }
        }

        echo "\nتمام داده‌های تست در دیتابیس باقی مانده‌اند.\n";
        echo "می‌توانید از طریق رابط کاربری سامانه آنها را مشاهده کنید.\n";
        echo str_repeat("=", 80) . "\n";
    }
}

// اجرای تست
try {
    $test = new ComprehensiveTenderTest();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}


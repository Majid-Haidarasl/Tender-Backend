<?php

/**
 * تست تراز و امتیاز فنی-بازرگانی
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Tender;
use App\Models\Bidder;
use App\Services\TechnicalCommercialScoreService;
use App\Services\StageReportService;
use App\Services\ComplianceCheckerService;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class TechnicalCommercialScoreTest
{
    private $technicalScoreService;
    private $testResults = [];

    public function __construct()
    {
        $this->technicalScoreService = new TechnicalCommercialScoreService();
    }

    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "تست تراز و امتیاز فنی-بازرگانی\n";
        echo str_repeat("=", 100) . "\n\n";

        $this->testPriceAdjustment();
        $this->testIncompleteTechnicalScores();
        $this->testInvalidTechnicalScores();
        $this->testInconsistentTechnicalScores();
        $this->testNonTwoStageTender();

        $this->printSummary();
    }

    /**
     * تست محاسبه تراز قیمت‌ها
     */
    private function testPriceAdjustment(): void
    {
        echo "\n[تست 1] محاسبه تراز قیمت‌ها\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTwoStageTender();
            $aMax = 100;

            $bidders = [
                [
                    'id' => 'bidder1',
                    'name' => 'شرکت الف',
                    'price' => 8000000,
                    'technical_score' => 80,
                ],
                [
                    'id' => 'bidder2',
                    'name' => 'شرکت ب',
                    'price' => 8500000,
                    'technical_score' => 90,
                ],
                [
                    'id' => 'bidder3',
                    'name' => 'شرکت ج',
                    'price' => 8200000,
                    'technical_score' => 85,
                ],
            ];

            $result = $this->technicalScoreService->calculateAdjustedPrices($tender->id, $bidders, $aMax);

            // بررسی محاسبه تراز
            $adjustmentCorrect = true;
            foreach ($result['adjusted_prices'] as $adjusted) {
                $expectedAdjusted = $adjusted['original_price'] * ($aMax / $adjusted['technical_score']);
                if (abs($adjusted['adjusted_price'] - $expectedAdjusted) > 0.01) {
                    $adjustmentCorrect = false;
                    break;
                }
            }

            $this->testResults[] = [
                'test' => 'محاسبه تراز قیمت‌ها',
                'adjustment_correct' => $adjustmentCorrect,
                'adjustment_applied' => !empty($result['adjusted_prices']),
                'passed' => $adjustmentCorrect && !empty($result['adjusted_prices']),
            ];

            echo $adjustmentCorrect ? "✓ تراز صحیح محاسبه شد\n" : "✗ تراز نادرست محاسبه شد\n";
            echo "  تعداد قیمت‌های تراز شده: " . count($result['adjusted_prices']) . "\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست داده‌های فنی ناقص
     */
    private function testIncompleteTechnicalScores(): void
    {
        echo "\n[تست 2] داده‌های فنی ناقص\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTwoStageTender();

            $bidders = [
                [
                    'id' => 'bidder1',
                    'name' => 'شرکت الف',
                    'price' => 8000000,
                    'technical_score' => 0, // ناقص
                ],
            ];

            $errorOccurred = false;
            try {
                $this->technicalScoreService->calculateAdjustedPrices($tender->id, $bidders, 100);
            } catch (\Exception $e) {
                $errorOccurred = true;
            }

            $this->testResults[] = [
                'test' => 'داده‌های فنی ناقص',
                'error_occurred' => $errorOccurred,
                'passed' => $errorOccurred,
            ];

            echo $errorOccurred ? "✓ خطا شناسایی شد\n" : "✗ خطا شناسایی نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست امتیازهای فنی نامعتبر
     */
    private function testInvalidTechnicalScores(): void
    {
        echo "\n[تست 3] امتیازهای فنی نامعتبر\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTwoStageTender();

            $bidders = [
                [
                    'id' => 'bidder1',
                    'name' => 'شرکت الف',
                    'price' => 8000000,
                    'technical_score' => 150, // نامعتبر (بیش از 100)
                ],
            ];

            $result = $this->technicalScoreService->calculateAdjustedPrices($tender->id, $bidders, 100);

            $warningGenerated = !empty($result['warnings']);

            $this->testResults[] = [
                'test' => 'امتیازهای فنی نامعتبر',
                'warning_generated' => $warningGenerated,
                'passed' => $warningGenerated,
            ];

            echo $warningGenerated ? "✓ هشدار تولید شد\n" : "✗ هشدار تولید نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست امتیازهای فنی ناسازگار
     */
    private function testInconsistentTechnicalScores(): void
    {
        echo "\n[تست 4] امتیازهای فنی ناسازگار\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTwoStageTender();

            $bidders = [
                [
                    'id' => 'bidder1',
                    'name' => 'شرکت الف',
                    'price' => 8000000,
                    'technical_score' => 90,
                ],
                [
                    'id' => 'bidder2',
                    'name' => 'شرکت ب',
                    'price' => 8500000,
                    'technical_score' => 90, // یکسان
                ],
                [
                    'id' => 'bidder3',
                    'name' => 'شرکت ج',
                    'price' => 8200000,
                    'technical_score' => 90, // یکسان
                ],
            ];

            $result = $this->technicalScoreService->calculateAdjustedPrices($tender->id, $bidders, 100);

            $warningGenerated = !empty($result['warnings']);

            $this->testResults[] = [
                'test' => 'امتیازهای فنی ناسازگار',
                'warning_generated' => $warningGenerated,
                'passed' => $warningGenerated,
            ];

            echo $warningGenerated ? "✓ هشدار ناسازگاری تولید شد\n" : "✗ هشدار تولید نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست مناقصه غیر دو مرحله‌ای
     */
    private function testNonTwoStageTender(): void
    {
        echo "\n[تست 5] مناقصه غیر دو مرحله‌ای\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender(['is_two_stage' => false]);

            $bidders = [
                [
                    'id' => 'bidder1',
                    'name' => 'شرکت الف',
                    'price' => 8000000,
                    'technical_score' => 0, // نباید خطا بدهد
                ],
            ];

            $result = $this->technicalScoreService->calculateAdjustedPrices($tender->id, $bidders, 100);

            $noAdjustment = true;
            foreach ($result['adjusted_prices'] as $adjusted) {
                if ($adjusted['adjustment_applied']) {
                    $noAdjustment = false;
                    break;
                }
            }

            $this->testResults[] = [
                'test' => 'مناقصه غیر دو مرحله‌ای',
                'no_adjustment' => $noAdjustment,
                'passed' => $noAdjustment,
            ];

            echo $noAdjustment ? "✓ تراز اعمال نشد (درست است)\n" : "✗ تراز اعمال شد (نادرست است)\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * ایجاد مناقصه دو مرحله‌ای
     */
    private function createTwoStageTender(): Tender
    {
        return $this->createTender(['is_two_stage' => true]);
    }

    /**
     * ایجاد مناقصه
     */
    private function createTender(array $config = []): Tender
    {
        return Tender::create([
            'code' => 'TEST-TECH-' . time(),
            'title' => 'مناقصه تست تراز',
            'type' => 'test',
            'date' => '1403/09/15',
            'is_two_stage' => $config['is_two_stage'] ?? true,
            'normalize_prices' => false,
            'is_adjustable' => true,
            'base_period' => '1403/01/01',
            'po_method' => '1',
            'tgamma' => 1.0,
            'tbeta' => 0.5,
            'delta' => 0,
            'a_max' => 100,
            'status' => 'active',
        ]);
    }

    /**
     * چاپ خلاصه نتایج
     */
    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "خلاصه نتایج تست تراز و امتیاز فنی-بازرگانی\n";
        echo str_repeat("=", 100) . "\n\n";

        $passedCount = count(array_filter($this->testResults, fn($r) => $r['passed']));
        $totalCount = count($this->testResults);

        echo "تعداد کل تست‌ها: {$totalCount}\n";
        echo "تست‌های موفق: {$passedCount}\n";
        echo "تست‌های ناموفق: " . ($totalCount - $passedCount) . "\n\n";

        foreach ($this->testResults as $result) {
            echo ($result['passed'] ? "✓" : "✗") . " {$result['test']}\n";
        }

        echo str_repeat("=", 100) . "\n";
    }
}

// اجرای تست
try {
    $test = new TechnicalCommercialScoreTest();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    exit(1);
}


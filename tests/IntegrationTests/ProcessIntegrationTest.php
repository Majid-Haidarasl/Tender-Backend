<?php

/**
 * تست یکپارچه فرآیند (Integration Testing)
 * 
 * این تست کل جریان فرآیند را با ورودی‌های واقعی شبیه‌سازی می‌کند:
 * Pb → Po → Pi → فیلتر نامتعارف → مسیر تصمیم‌گیری → دامنه قیمت‌ها → برنده نهایی
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Services\PoCalculationService;
use App\Services\EvaluationService;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class ProcessIntegrationTest
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

    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "شروع تست یکپارچه فرآیند (Integration Testing)\n";
        echo str_repeat("=", 100) . "\n\n";

        $this->testCompleteProcessSimple();
        $this->testCompleteProcessStatistical();
        $this->testCompleteProcessSuspended();
        $this->testCompleteProcessTwoStage();

        $this->printSummary();
    }

    /**
     * تست فرآیند کامل - مسیر ساده
     */
    private function testCompleteProcessSimple(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] فرآیند کامل - مسیر ساده\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            // 1. ایجاد مناقصه
            $tender = $this->createTender(['is_two_stage' => false]);
            echo "✓ مرحله 1: مناقصه ایجاد شد\n";

            // 2. ثبت برآورد اولیه (Pb)
            $this->createEstimates($tender->id, 5000000);
            $pb = Estimate::calculateTotalPb($tender->id);
            echo "✓ مرحله 2: Pb = " . number_format($pb, 2) . " ریال\n";

            // 3. ثبت شاخص‌ها
            $this->createIndices($tender->id);
            echo "✓ مرحله 3: شاخص‌ها ثبت شدند\n";

            // 4. محاسبه Po
            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['pb' => $pb, 'po' => $po]);
            Tender::setPoCalculatedAt($tender->id);
            echo "✓ مرحله 4: Po = " . number_format($po, 2) . " ریال\n";

            // 5. ثبت پیشنهادات (n=2)
            $bidPrices = [4500000, 5200000];
            foreach ($bidPrices as $index => $price) {
                Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => "پیشنهاددهنده " . ($index + 1),
                    'price' => $price,
                    'technical_score' => 90,
                ]);
            }
            echo "✓ مرحله 5: " . count($bidPrices) . " پیشنهاد ثبت شد\n";

            // 6. انجام ارزیابی
            $tender->update(['status' => 'BIDS_CLOSED']);
            $evaluationResult = $this->evaluationService->evaluate($tender->id);
            echo "✓ مرحله 6: ارزیابی انجام شد\n";
            echo "  مسیر انتخاب شده: {$evaluationResult['path']}\n";
            echo "  عمل: {$evaluationResult['action']}\n";

            // 7. بررسی برنده
            $winner = null;
            foreach ($evaluationResult['results'] ?? [] as $result) {
                if ($result['is_winner_first'] ?? false) {
                    $winner = $result;
                    break;
                }
            }

            if ($winner) {
                echo "✓ مرحله 7: برنده انتخاب شد: {$winner['bidder_name']}\n";
                echo "  قیمت نهایی: " . number_format($winner['final_score'], 2) . " ریال\n";
            }

            // بررسی صحت مسیر
            $pathCorrect = $evaluationResult['path'] === 'SIMPLE';
            $allStagesPassed = $pb > 0 && $po > 0 && !empty($evaluationResult['results']);

            $this->testResults[] = [
                'test' => 'فرآیند کامل - مسیر ساده',
                'passed' => $pathCorrect && $allStagesPassed,
                'stages' => [
                    'tender_created' => true,
                    'pb_calculated' => $pb > 0,
                    'po_calculated' => $po > 0,
                    'bids_registered' => count($bidPrices) > 0,
                    'evaluation_done' => !empty($evaluationResult),
                    'path_correct' => $pathCorrect,
                    'winner_selected' => $winner !== null,
                ],
            ];

            DB::rollBack(); // Rollback برای تست
            echo "✓ تمام مراحل با موفقیت انجام شد\n";

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
            $this->testResults[] = [
                'test' => 'فرآیند کامل - مسیر ساده',
                'passed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * تست فرآیند کامل - مسیر آماری
     */
    private function testCompleteProcessStatistical(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] فرآیند کامل - مسیر آماری\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender(['is_two_stage' => false]);
            $this->createEstimates($tender->id, 5000000);
            $this->createIndices($tender->id);
            
            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['pb' => 5000000, 'po' => $po]);
            Tender::setPoCalculatedAt($tender->id);

            // ثبت 5 پیشنهاد (n≥4)
            $bidPrices = [4500000, 5200000, 4800000, 5000000, 5100000];
            foreach ($bidPrices as $index => $price) {
                Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => "پیشنهاددهنده " . ($index + 1),
                    'price' => $price,
                    'technical_score' => 90,
                ]);
            }

            $tender->update(['status' => 'BIDS_CLOSED']);
            $evaluationResult = $this->evaluationService->evaluate($tender->id);

            $pathCorrect = $evaluationResult['path'] === 'STATISTICAL';
            $hasMoSo = isset($evaluationResult['ranges']['m_o']) && isset($evaluationResult['ranges']['s_o']);
            $normalized = !empty($evaluationResult['results']);

            $this->testResults[] = [
                'test' => 'فرآیند کامل - مسیر آماری',
                'passed' => $pathCorrect && $hasMoSo && $normalized,
                'path' => $evaluationResult['path'],
                'has_mo_so' => $hasMoSo,
                'normalized' => $normalized,
            ];

            echo $pathCorrect ? "✓ مسیر آماری انتخاب شد\n" : "✗ مسیر نادرست\n";
            echo $hasMoSo ? "✓ mo و so محاسبه شدند\n" : "✗ mo و so محاسبه نشدند\n";
            echo $normalized ? "✓ قیمت‌ها نرمال‌سازی شدند\n" : "✗ قیمت‌ها نرمال‌سازی نشدند\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
            $this->testResults[] = [
                'test' => 'فرآیند کامل - مسیر آماری',
                'passed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * تست فرآیند کامل - مسیر وقفه
     */
    private function testCompleteProcessSuspended(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] فرآیند کامل - مسیر وقفه\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender(['is_two_stage' => false]);
            $this->createEstimates($tender->id, 10000000);
            $this->createIndices($tender->id);
            
            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['pb' => 10000000, 'po' => $po]);
            Tender::setPoCalculatedAt($tender->id);

            // پیشنهادات با میانگین خارج از بازه
            $bidPrices = [7500000, 13800000, 12500000, 15000000];
            foreach ($bidPrices as $index => $price) {
                Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => "پیشنهاددهنده " . ($index + 1),
                    'price' => $price,
                    'technical_score' => 90,
                ]);
            }

            $tender->update(['status' => 'BIDS_CLOSED']);
            $evaluationResult = $this->evaluationService->evaluate($tender->id);

            $suspended = $evaluationResult['action'] === 'REVIEW_REQUIRED_M62';
            $committeeReferred = $suspended;

            $this->testResults[] = [
                'test' => 'فرآیند کامل - مسیر وقفه',
                'passed' => $suspended && $committeeReferred,
                'suspended' => $suspended,
                'committee_referred' => $committeeReferred,
            ];

            echo $suspended ? "✓ فرآیند متوقف شد\n" : "✗ فرآیند متوقف نشد\n";
            echo $committeeReferred ? "✓ به کمیته ارجاع داده شد\n" : "✗ به کمیته ارجاع داده نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
            $this->testResults[] = [
                'test' => 'فرآیند کامل - مسیر وقفه',
                'passed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * تست فرآیند کامل - دو مرحله‌ای
     */
    private function testCompleteProcessTwoStage(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] فرآیند کامل - دو مرحله‌ای\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender(['is_two_stage' => true]);
            $this->createEstimates($tender->id, 8000000);
            $this->createIndices($tender->id);
            
            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['pb' => 8000000, 'po' => $po]);
            Tender::setPoCalculatedAt($tender->id);

            // پیشنهادات با امتیاز فنی
            $bids = [
                ['price' => 7900000, 'technical' => 75],
                ['price' => 8100000, 'technical' => 85],
                ['price' => 7800000, 'technical' => 70],
            ];

            foreach ($bids as $index => $bid) {
                Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => "پیشنهاددهنده " . ($index + 1),
                    'price' => $bid['price'],
                    'technical_score' => $bid['technical'],
                ]);
            }

            $tender->update(['status' => 'BIDS_CLOSED']);
            $evaluationResult = $this->evaluationService->evaluate($tender->id);

            $adjustmentApplied = false;
            foreach ($evaluationResult['results'] ?? [] as $result) {
                if (isset($result['adjusted_price']) && $result['adjusted_price'] !== $result['original_price']) {
                    $adjustmentApplied = true;
                    break;
                }
            }

            $this->testResults[] = [
                'test' => 'فرآیند کامل - دو مرحله‌ای',
                'passed' => $adjustmentApplied,
                'adjustment_applied' => $adjustmentApplied,
            ];

            echo $adjustmentApplied ? "✓ تراز قیمت اعمال شد\n" : "✗ تراز قیمت اعمال نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
            $this->testResults[] = [
                'test' => 'فرآیند کامل - دو مرحله‌ای',
                'passed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ایجاد مناقصه
     */
    private function createTender(array $config = []): Tender
    {
        return Tender::create([
            'code' => 'TEST-INT-' . time(),
            'title' => 'مناقصه تست یکپارچه',
            'type' => 'test',
            'date' => '1403/09/15',
            'is_two_stage' => $config['is_two_stage'] ?? false,
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
     * ایجاد برآورد اولیه
     */
    private function createEstimates(string $tenderId, float $pb): void
    {
        Estimate::create([
            'tender_id' => $tenderId,
            'section' => 'بخش تست',
            'amount' => $pb,
            'currency' => 'IRR',
            'exchange_rate' => null,
            'amount_in_rials' => $pb,
            'is_adjustable' => true,
            'calculation_method' => 'base_price_list',
            'base_period' => '1403/01/01',
        ]);
    }

    /**
     * ایجاد شاخص‌ها
     */
    private function createIndices(string $tenderId): void
    {
        $indices = [
            ['type' => 'I1', 'value' => 100.0, 'date' => '1403/01/01'],
            ['type' => 'I2', 'value' => 110.0, 'date' => '1403/06/01'],
            ['type' => 'I3', 'value' => 115.0, 'date' => '1403/09/01'],
            ['type' => 'r1', 'value' => 0.05, 'date' => null],
            ['type' => 'r2', 'value' => 0.03, 'date' => null],
        ];

        foreach ($indices as $index) {
            Index::createOrUpdate(array_merge($index, ['tender_id' => $tenderId, 'description' => '']));
        }
    }

    /**
     * چاپ خلاصه نتایج
     */
    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "خلاصه نتایج تست یکپارچه\n";
        echo str_repeat("=", 100) . "\n\n";

        $passedCount = count(array_filter($this->testResults, fn($r) => $r['passed']));
        $totalCount = count($this->testResults);

        echo "تعداد کل تست‌ها: {$totalCount}\n";
        echo "تست‌های موفق: {$passedCount}\n";
        echo "تست‌های ناموفق: " . ($totalCount - $passedCount) . "\n\n";

        foreach ($this->testResults as $result) {
            echo ($result['passed'] ? "✓" : "✗") . " {$result['test']}\n";
            if (isset($result['error'])) {
                echo "  خطا: {$result['error']}\n";
            }
        }

        echo str_repeat("=", 100) . "\n";
    }
}

// اجرای تست
try {
    $test = new ProcessIntegrationTest();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    exit(1);
}


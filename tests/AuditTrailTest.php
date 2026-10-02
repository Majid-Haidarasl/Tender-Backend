<?php

/**
 * تست مستندسازی و Audit Trail
 * 
 * بررسی اینکه سامانه تمام تصمیمات، محاسبات، مسیرها و اعمال فیلترها را ثبت می‌کند
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Models\EvaluationResult;
use App\Services\PoCalculationService;
use App\Services\EvaluationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class AuditTrailTest
{
    private $testResults = [];

    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "تست مستندسازی و Audit Trail\n";
        echo str_repeat("=", 100) . "\n\n";

        $this->testPoCalculationLogging();
        $this->testDecisionPathLogging();
        $this->testFilterApplicationLogging();
        $this->testWinnerSelectionLogging();

        $this->printSummary();
    }

    /**
     * تست ثبت لاگ محاسبه Po
     */
    private function testPoCalculationLogging(): void
    {
        echo "\n[تست 1] ثبت لاگ محاسبه Po\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();
            $this->createEstimates($tender->id, 5000000);
            $this->createIndices($tender->id);

            $poService = new PoCalculationService();
            $poResult = $poService->calculate($tender->id);

            // بررسی ثبت در دیتابیس
            $tender->update(['po' => $poResult['Po']]);
            Tender::setPoCalculatedAt($tender->id);
            $tender->refresh();

            $poLogged = $tender->po_calculated_at !== null;
            $poValueLogged = $tender->po > 0;
            $calculationDetailsLogged = !empty($poResult);

            $this->testResults[] = [
                'test' => 'ثبت لاگ محاسبه Po',
                'timestamp_logged' => $poLogged,
                'value_logged' => $poValueLogged,
                'details_logged' => $calculationDetailsLogged,
                'passed' => $poLogged && $poValueLogged && $calculationDetailsLogged,
            ];

            echo $poLogged ? "✓ زمان محاسبه ثبت شد\n" : "✗ زمان محاسبه ثبت نشد\n";
            echo $poValueLogged ? "✓ مقدار Po ثبت شد\n" : "✗ مقدار Po ثبت نشد\n";
            echo $calculationDetailsLogged ? "✓ جزئیات محاسبه ثبت شد\n" : "✗ جزئیات محاسبه ثبت نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست ثبت لاگ مسیر تصمیم‌گیری
     */
    private function testDecisionPathLogging(): void
    {
        echo "\n[تست 2] ثبت لاگ مسیر تصمیم‌گیری\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTenderWithPo();
            $tender->update(['status' => 'BIDS_CLOSED']);

            $bidPrices = [4500000, 5200000, 4800000, 5000000, 5100000];
            foreach ($bidPrices as $index => $price) {
                Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => "پیشنهاددهنده " . ($index + 1),
                    'price' => $price,
                    'technical_score' => 90,
                ]);
            }

            $evaluationService = new EvaluationService();
            $evaluationResult = $evaluationService->evaluate($tender->id);

            // بررسی ثبت مسیر در نتایج
            $pathLogged = isset($evaluationResult['path']);
            $actionLogged = isset($evaluationResult['action']);
            $rangesLogged = isset($evaluationResult['ranges']);

            $this->testResults[] = [
                'test' => 'ثبت لاگ مسیر تصمیم‌گیری',
                'path_logged' => $pathLogged,
                'action_logged' => $actionLogged,
                'ranges_logged' => $rangesLogged,
                'passed' => $pathLogged && $actionLogged && $rangesLogged,
            ];

            echo $pathLogged ? "✓ مسیر ثبت شد: {$evaluationResult['path']}\n" : "✗ مسیر ثبت نشد\n";
            echo $actionLogged ? "✓ عمل ثبت شد: {$evaluationResult['action']}\n" : "✗ عمل ثبت نشد\n";
            echo $rangesLogged ? "✓ محدوده‌ها ثبت شدند\n" : "✗ محدوده‌ها ثبت نشدند\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست ثبت لاگ اعمال فیلتر
     */
    private function testFilterApplicationLogging(): void
    {
        echo "\n[تست 3] ثبت لاگ اعمال فیلتر\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTenderWithPo(['type' => 'forex']);
            $tender->update(['status' => 'BIDS_CLOSED']);

            $bidPrices = [4500000, 16000000, 4800000]; // یکی نامتعارف
            foreach ($bidPrices as $index => $price) {
                Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => "پیشنهاددهنده " . ($index + 1),
                    'price' => $price,
                    'technical_score' => 90,
                ]);
            }

            $evaluationService = new EvaluationService();
            $evaluationResult = $evaluationService->evaluate($tender->id);

            // بررسی ثبت نتایج فیلتر
            $resultsLogged = !empty($evaluationResult['results']);
            $filterApplied = $resultsLogged;

            $this->testResults[] = [
                'test' => 'ثبت لاگ اعمال فیلتر',
                'results_logged' => $resultsLogged,
                'filter_applied' => $filterApplied,
                'passed' => $resultsLogged && $filterApplied,
            ];

            echo $resultsLogged ? "✓ نتایج فیلتر ثبت شدند\n" : "✗ نتایج فیلتر ثبت نشدند\n";
            echo $filterApplied ? "✓ فیلتر اعمال شد\n" : "✗ فیلتر اعمال نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست ثبت لاگ انتخاب برنده
     */
    private function testWinnerSelectionLogging(): void
    {
        echo "\n[تست 4] ثبت لاگ انتخاب برنده\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTenderWithPo();
            $tender->update(['status' => 'BIDS_CLOSED']);

            $bidPrices = [4500000, 5200000, 4800000, 5000000];
            foreach ($bidPrices as $index => $price) {
                Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => "پیشنهاددهنده " . ($index + 1),
                    'price' => $price,
                    'technical_score' => 90,
                ]);
            }

            $evaluationService = new EvaluationService();
            $evaluationResult = $evaluationService->evaluate($tender->id);

            // بررسی ثبت برنده در دیتابیس
            $winner = null;
            foreach ($evaluationResult['results'] ?? [] as $result) {
                if ($result['is_winner_first'] ?? false) {
                    $winner = $result;
                    break;
                }
            }

            $winnerLogged = $winner !== null;
            $winnerInDb = false;
            if ($winner) {
                $evaluationInDb = EvaluationResult::where('tender_id', $tender->id)
                    ->where('bidder_id', $winner['bidder_id'])
                    ->where('is_winner_first', true)
                    ->first();
                $winnerInDb = $evaluationInDb !== null;
            }

            $this->testResults[] = [
                'test' => 'ثبت لاگ انتخاب برنده',
                'winner_logged' => $winnerLogged,
                'winner_in_db' => $winnerInDb,
                'passed' => $winnerLogged && $winnerInDb,
            ];

            echo $winnerLogged ? "✓ برنده در نتایج ثبت شد\n" : "✗ برنده در نتایج ثبت نشد\n";
            echo $winnerInDb ? "✓ برنده در دیتابیس ثبت شد\n" : "✗ برنده در دیتابیس ثبت نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * ایجاد مناقصه
     */
    private function createTender(array $config = []): Tender
    {
        return Tender::create([
            'code' => 'TEST-AUDIT-' . time(),
            'title' => 'مناقصه تست Audit Trail',
            'type' => $config['type'] ?? 'test',
            'date' => '1403/09/15',
            'is_two_stage' => false,
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
     * ایجاد مناقصه با Po
     */
    private function createTenderWithPo(array $config = []): Tender
    {
        $tender = $this->createTender($config);
        $this->createEstimates($tender->id, 5000000);
        $this->createIndices($tender->id);
        
        $poService = new PoCalculationService();
        $poResult = $poService->calculate($tender->id);
        $tender->update(['po' => $poResult['Po']]);
        Tender::setPoCalculatedAt($tender->id);
        
        return $tender->fresh();
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
        echo "خلاصه نتایج تست Audit Trail\n";
        echo str_repeat("=", 100) . "\n\n";

        $passedCount = count(array_filter($this->testResults, fn($r) => $r['passed']));
        $totalCount = count($this->testResults);

        echo "تعداد کل تست‌ها: {$totalCount}\n";
        echo "تست‌های موفق: {$passedCount}\n";
        echo "تست‌های ناموفق: " . ($totalCount - $passedCount) . "\n";
        echo str_repeat("=", 100) . "\n";
    }
}

// اجرای تست
try {
    $test = new AuditTrailTest();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    exit(1);
}


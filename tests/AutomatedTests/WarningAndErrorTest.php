<?php

/**
 * تست خودکار و هشدارها
 * 
 * بررسی اینکه سامانه در صورت ورود داده ناقص، نامتعارف یا غیرمجاز، هشدار دقیق و قابل ردیابی بدهد
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Services\PoCalculationService;
use App\Services\EvaluationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class WarningAndErrorTest
{
    private $testResults = [];
    private $testCounter = 0;

    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "تست خودکار و هشدارها\n";
        echo str_repeat("=", 100) . "\n\n";

        $this->testIncompleteDataWarning();
        $this->testAbnormalDataWarning();
        $this->testInvalidDataWarning();
        $this->testMissingPbPoWarning();
        $this->testOutOfRangeWarning();
        $this->testProcessBlocking();

        $this->printSummary();
    }

    /**
     * تست هشدار داده ناقص
     */
    private function testIncompleteDataWarning(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] هشدار داده ناقص\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();
            $this->createEstimates($tender->id, 5000000);
            $this->createIncompleteIndices($tender->id);

            $poService = new PoCalculationService();
            $warningGenerated = false;
            $errorMessage = '';

            try {
                $poService->calculate($tender->id);
            } catch (\Exception $e) {
                $warningGenerated = true;
                $errorMessage = $e->getMessage();
            }

            $this->testResults[] = [
                'test' => 'هشدار داده ناقص',
                'warning_generated' => $warningGenerated,
                'error_message' => $errorMessage,
                'trackable' => !empty($errorMessage),
                'passed' => $warningGenerated && !empty($errorMessage),
            ];

            echo $warningGenerated ? "✓ هشدار تولید شد\n" : "✗ هشدار تولید نشد\n";
            echo $errorMessage ? "  پیام: {$errorMessage}\n" : "  پیام وجود ندارد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست هشدار داده نامتعارف
     */
    private function testAbnormalDataWarning(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] هشدار داده نامتعارف\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTenderWithPo();
            $tender->update(['status' => 'BIDS_CLOSED']);

            // پیشنهاد با قیمت بسیار بالا (Pi > 3×Po)
            $po = floatval($tender->po ?? 5000000);
            $abnormalPrice = $po * 4; // 4 برابر Po

            $bidder = Bidder::create([
                'tender_id' => $tender->id,
                'name' => 'پیشنهاددهنده نامتعارف',
                'price' => $abnormalPrice,
                'technical_score' => 90,
            ]);

            $evaluationService = new EvaluationService();
            $evaluationResult = $evaluationService->evaluate($tender->id);

            // بررسی اینکه آیا پیشنهاد نامتعارف شناسایی شده
            $abnormalDetected = false;
            foreach ($evaluationResult['results'] ?? [] as $result) {
                if ($result['bidder_id'] === $bidder->id && !($result['in_final_range'] ?? true)) {
                    $abnormalDetected = true;
                    break;
                }
            }

            $this->testResults[] = [
                'test' => 'هشدار داده نامتعارف',
                'abnormal_detected' => $abnormalDetected,
                'warning_generated' => $abnormalDetected,
                'passed' => $abnormalDetected,
            ];

            echo $abnormalDetected ? "✓ داده نامتعارف شناسایی شد\n" : "✗ داده نامتعارف شناسایی نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست هشدار داده نامعتبر
     */
    private function testInvalidDataWarning(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] هشدار داده نامعتبر\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();
            $this->createEstimates($tender->id, 5000000);
            $this->createInvalidIndices($tender->id);

            $poService = new PoCalculationService();
            $warningGenerated = false;
            $errorMessage = '';

            try {
                $poService->calculate($tender->id);
            } catch (\Exception $e) {
                $warningGenerated = true;
                $errorMessage = $e->getMessage();
            }

            $this->testResults[] = [
                'test' => 'هشدار داده نامعتبر',
                'warning_generated' => $warningGenerated,
                'error_message' => $errorMessage,
                'trackable' => !empty($errorMessage),
                'passed' => $warningGenerated && !empty($errorMessage),
            ];

            echo $warningGenerated ? "✓ هشدار تولید شد\n" : "✗ هشدار تولید نشد\n";
            echo $errorMessage ? "  پیام: {$errorMessage}\n" : "  پیام وجود ندارد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست هشدار عدم وجود Pb و Po
     */
    private function testMissingPbPoWarning(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] هشدار عدم وجود Pb و Po\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();
            $tender->update(['pb' => 0, 'po' => 0, 'status' => 'BIDS_CLOSED']);

            // ثبت پیشنهاد بدون Pb و Po
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => 'پیشنهاددهنده تست',
                'price' => 5000000,
                'technical_score' => 90,
            ]);

            $evaluationService = new EvaluationService();
            $warningGenerated = false;
            $errorMessage = '';

            try {
                $evaluationService->evaluate($tender->id);
            } catch (\Exception $e) {
                $warningGenerated = true;
                $errorMessage = $e->getMessage();
            }

            $this->testResults[] = [
                'test' => 'هشدار عدم وجود Pb و Po',
                'warning_generated' => $warningGenerated,
                'error_message' => $errorMessage,
                'process_blocked' => $warningGenerated,
                'passed' => $warningGenerated && !empty($errorMessage),
            ];

            echo $warningGenerated ? "✓ هشدار تولید شد\n" : "✗ هشدار تولید نشد\n";
            echo $warningGenerated ? "✓ فرآیند مسدود شد\n" : "✗ فرآیند مسدود نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست هشدار خارج از محدوده
     */
    private function testOutOfRangeWarning(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] هشدار خارج از محدوده\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTenderWithPo();
            $tender->update(['status' => 'BIDS_CLOSED']);

            $po = floatval($tender->po ?? 5000000);
            $outOfRangePrice = $po * 1.5; // خارج از محدوده ±10%

            Bidder::create([
                'tender_id' => $tender->id,
                'name' => 'پیشنهاددهنده خارج از محدوده',
                'price' => $outOfRangePrice,
                'technical_score' => 90,
            ]);

            $evaluationService = new EvaluationService();
            $evaluationResult = $evaluationService->evaluate($tender->id);

            $outOfRangeDetected = false;
            foreach ($evaluationResult['results'] ?? [] as $result) {
                if (!($result['in_final_range'] ?? true)) {
                    $outOfRangeDetected = true;
                    break;
                }
            }

            $this->testResults[] = [
                'test' => 'هشدار خارج از محدوده',
                'out_of_range_detected' => $outOfRangeDetected,
                'warning_generated' => $outOfRangeDetected,
                'passed' => $outOfRangeDetected,
            ];

            echo $outOfRangeDetected ? "✓ خارج از محدوده شناسایی شد\n" : "✗ خارج از محدوده شناسایی نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست مسدودسازی فرآیند
     */
    private function testProcessBlocking(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] مسدودسازی فرآیند\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();
            $this->createEstimates($tender->id, 5000000);
            $this->createIncompleteIndices($tender->id);

            $poService = new PoCalculationService();
            $processBlocked = false;

            try {
                $poService->calculate($tender->id);
            } catch (\Exception $e) {
                $processBlocked = true;
            }

            // بررسی اینکه نمی‌توان تصمیم گرفت
            $tender->update(['status' => 'BIDS_CLOSED']);
            $evaluationService = new EvaluationService();
            $evaluationBlocked = false;

            try {
                $evaluationService->evaluate($tender->id);
            } catch (\Exception $e) {
                $evaluationBlocked = true;
            }

            $this->testResults[] = [
                'test' => 'مسدودسازی فرآیند',
                'po_blocked' => $processBlocked,
                'evaluation_blocked' => $evaluationBlocked,
                'passed' => $processBlocked && $evaluationBlocked,
            ];

            echo $processBlocked ? "✓ محاسبه Po مسدود شد\n" : "✗ محاسبه Po مسدود نشد\n";
            echo $evaluationBlocked ? "✓ ارزیابی مسدود شد\n" : "✗ ارزیابی مسدود نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * ایجاد مناقصه
     */
    private function createTender(): Tender
    {
        return Tender::create([
            'code' => 'TEST-WARN-' . time(),
            'title' => 'مناقصه تست هشدار',
            'type' => 'test',
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
    private function createTenderWithPo(): Tender
    {
        $tender = $this->createTender();
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
     * ایجاد شاخص‌های ناقص
     */
    private function createIncompleteIndices(string $tenderId): void
    {
        Index::createOrUpdate([
            'tender_id' => $tenderId,
            'type' => 'I1',
            'value' => 100.0,
            'date' => '1403/01/01',
            'description' => 'شاخص I1',
        ]);
        // I2 و I3 ناقص هستند
    }

    /**
     * ایجاد شاخص‌های نامعتبر
     */
    private function createInvalidIndices(string $tenderId): void
    {
        Index::createOrUpdate([
            'tender_id' => $tenderId,
            'type' => 'I1',
            'value' => 100.0,
            'date' => '1403/01/01',
            'description' => 'شاخص I1',
        ]);
        Index::createOrUpdate([
            'tender_id' => $tenderId,
            'type' => 'I2',
            'value' => -10, // نامعتبر
            'date' => '1403/06/01',
            'description' => 'شاخص I2 (نامعتبر)',
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
        echo "خلاصه نتایج تست هشدارها\n";
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
    $test = new WarningAndErrorTest();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    exit(1);
}


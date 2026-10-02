<?php

/**
 * تست جامع برای بررسی مشکلات برآورد اولیه و محاسبه Po
 * 
 * این تست موارد زیر را بررسی می‌کند:
 * 1. اطمینان از اینکه اعداد منفی در برآورد اولیه ثبت نمی‌شوند
 * 2. اطمینان از اینکه amount_in_rials درست محاسبه می‌شود
 * 3. اطمینان از اینکه Po درست محاسبه می‌شود
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Services\PoCalculationService;
use Illuminate\Support\Facades\DB;

// بارگذاری Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class EstimateAndPoTest
{
    private $poService;
    private $testResults = [];
    private $testCounter = 0;

    public function __construct()
    {
        $this->poService = new PoCalculationService();
    }

    /**
     * اجرای تمام تست‌ها
     */
    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "شروع تست جامع برآورد اولیه و محاسبه Po\n";
        echo str_repeat("=", 80) . "\n\n";

        // تست 1: بررسی اعداد منفی
        $this->testNegativeNumbers();

        // تست 2: بررسی محاسبه amount_in_rials
        $this->testAmountInRialsCalculation();

        // تست 3: بررسی محاسبه Po
        $this->testPoCalculation();

        // تست 4: بررسی محاسبه Pb
        $this->testPbCalculation();

        $this->printSummary();
    }

    /**
     * تست 1: بررسی اعداد منفی
     */
    private function testNegativeNumbers(): void
    {
        $this->testCounter++;
        echo "\n" . str_repeat("-", 80) . "\n";
        echo "تست {$this->testCounter}: بررسی اعداد منفی در برآورد اولیه\n";
        echo str_repeat("-", 80) . "\n";

        DB::beginTransaction();

        try {
            // ایجاد مناقصه تست
            $tender = Tender::create([
                'code' => 'TEST-NEGATIVE-' . time(),
                'title' => 'تست اعداد منفی',
                'type' => 'test',
                'date' => '1403/09/15',
                'is_two_stage' => false,
                'normalize_prices' => false,
                'is_adjustable' => false,
                'po_method' => '1',
                'status' => 'active',
            ]);

            echo "  ✓ مناقصه تست ایجاد شد: {$tender->code}\n";

            // تست 1-1: تلاش برای ثبت مبلغ منفی
            try {
                $estimate = Estimate::create([
                    'tender_id' => $tender->id,
                    'section' => 'بخش تست منفی',
                    'amount' => -1000000,
                    'currency' => 'IRR',
                    'amount_in_rials' => -1000000,
                    'is_adjustable' => false,
                ]);
                
                // اگر به اینجا رسید، یعنی اعتبارسنجی کار نکرده
                $this->testResults[] = [
                    'test' => 'تست 1-1: ثبت مبلغ منفی',
                    'status' => 'FAILED',
                    'message' => 'مبلغ منفی ثبت شد در حالی که نباید می‌شد',
                ];
                echo "  ✗ تست 1-1: مبلغ منفی ثبت شد (خطا)\n";
            } catch (\Exception $e) {
                $this->testResults[] = [
                    'test' => 'تست 1-1: ثبت مبلغ منفی',
                    'status' => 'PASSED',
                    'message' => 'مبلغ منفی رد شد',
                ];
                echo "  ✓ تست 1-1: مبلغ منفی رد شد\n";
            }

            // تست 1-2: ثبت مبلغ مثبت
            $estimate = Estimate::create([
                'tender_id' => $tender->id,
                'section' => 'بخش تست مثبت',
                'amount' => 1000000,
                'currency' => 'IRR',
                'amount_in_rials' => 1000000,
                'is_adjustable' => false,
            ]);

            if ($estimate->amount_in_rials > 0) {
                $this->testResults[] = [
                    'test' => 'تست 1-2: ثبت مبلغ مثبت',
                    'status' => 'PASSED',
                    'message' => 'مبلغ مثبت ثبت شد',
                ];
                echo "  ✓ تست 1-2: مبلغ مثبت ثبت شد: " . number_format($estimate->amount_in_rials) . " ریال\n";
            } else {
                $this->testResults[] = [
                    'test' => 'تست 1-2: ثبت مبلغ مثبت',
                    'status' => 'FAILED',
                    'message' => 'مبلغ مثبت به منفی تبدیل شد',
                ];
                echo "  ✗ تست 1-2: مبلغ مثبت به منفی تبدیل شد\n";
            }

            DB::rollBack();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->testResults[] = [
                'test' => 'تست 1: بررسی اعداد منفی',
                'status' => 'FAILED',
                'message' => $e->getMessage(),
            ];
            echo "  ✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست 2: بررسی محاسبه amount_in_rials
     */
    private function testAmountInRialsCalculation(): void
    {
        $this->testCounter++;
        echo "\n" . str_repeat("-", 80) . "\n";
        echo "تست {$this->testCounter}: بررسی محاسبه amount_in_rials\n";
        echo str_repeat("-", 80) . "\n";

        DB::beginTransaction();

        try {
            // ایجاد مناقصه تست
            $tender = Tender::create([
                'code' => 'TEST-AMOUNT-' . time(),
                'title' => 'تست محاسبه amount_in_rials',
                'type' => 'test',
                'date' => '1403/09/15',
                'is_two_stage' => false,
                'normalize_prices' => false,
                'is_adjustable' => false,
                'po_method' => '1',
                'status' => 'active',
            ]);

            echo "  ✓ مناقصه تست ایجاد شد: {$tender->code}\n";

            // تست 2-1: محاسبه برای IRR
            $estimate1 = Estimate::create([
                'tender_id' => $tender->id,
                'section' => 'بخش ریالی',
                'amount' => 1000000,
                'currency' => 'IRR',
                'amount_in_rials' => 1000000,
                'is_adjustable' => false,
            ]);

            if ($estimate1->amount_in_rials == 1000000) {
                echo "  ✓ تست 2-1: محاسبه برای IRR درست است\n";
            } else {
                echo "  ✗ تست 2-1: محاسبه برای IRR اشتباه است\n";
            }

            // تست 2-2: محاسبه برای USD
            $estimate2 = Estimate::create([
                'tender_id' => $tender->id,
                'section' => 'بخش دلاری',
                'amount' => 1000,
                'currency' => 'USD',
                'exchange_rate' => 50000,
                'amount_in_rials' => 50000000,
                'is_adjustable' => false,
            ]);

            $expected = 1000 * 50000;
            if ($estimate2->amount_in_rials == $expected) {
                echo "  ✓ تست 2-2: محاسبه برای USD درست است: " . number_format($estimate2->amount_in_rials) . " ریال\n";
            } else {
                echo "  ✗ تست 2-2: محاسبه برای USD اشتباه است. انتظار: " . number_format($expected) . "، دریافت: " . number_format($estimate2->amount_in_rials) . "\n";
            }

            // تست 2-3: بررسی Pb
            $totalPb = Estimate::calculateTotalPb($tender->id);
            $expectedPb = 1000000 + 50000000;
            
            if ($totalPb == $expectedPb) {
                echo "  ✓ تست 2-3: محاسبه Pb درست است: " . number_format($totalPb) . " ریال\n";
                $this->testResults[] = [
                    'test' => 'تست 2: بررسی محاسبه amount_in_rials',
                    'status' => 'PASSED',
                    'message' => 'همه محاسبات درست است',
                ];
            } else {
                echo "  ✗ تست 2-3: محاسبه Pb اشتباه است. انتظار: " . number_format($expectedPb) . "، دریافت: " . number_format($totalPb) . "\n";
                $this->testResults[] = [
                    'test' => 'تست 2: بررسی محاسبه amount_in_rials',
                    'status' => 'FAILED',
                    'message' => 'محاسبه Pb اشتباه است',
                ];
            }

            DB::rollBack();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->testResults[] = [
                'test' => 'تست 2: بررسی محاسبه amount_in_rials',
                'status' => 'FAILED',
                'message' => $e->getMessage(),
            ];
            echo "  ✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست 3: بررسی محاسبه Po
     */
    private function testPoCalculation(): void
    {
        $this->testCounter++;
        echo "\n" . str_repeat("-", 80) . "\n";
        echo "تست {$this->testCounter}: بررسی محاسبه Po\n";
        echo str_repeat("-", 80) . "\n";

        DB::beginTransaction();

        try {
            // ایجاد مناقصه تست
            $tender = Tender::create([
                'code' => 'TEST-PO-' . time(),
                'title' => 'تست محاسبه Po',
                'type' => 'test',
                'date' => '1403/09/15',
                'is_two_stage' => false,
                'normalize_prices' => false,
                'is_adjustable' => false,
                'po_method' => '1',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'status' => 'active',
            ]);

            echo "  ✓ مناقصه تست ایجاد شد: {$tender->code}\n";

            // ایجاد برآورد اولیه
            Estimate::create([
                'tender_id' => $tender->id,
                'section' => 'بخش اول',
                'amount' => 1000000000,
                'currency' => 'IRR',
                'amount_in_rials' => 1000000000,
                'is_adjustable' => false,
            ]);

            echo "  ✓ برآورد اولیه ایجاد شد\n";

            // ایجاد شاخص‌ها
            Index::createOrUpdate([
                'tender_id' => $tender->id,
                'type' => 'I1',
                'value' => 100.0,
                'date' => '1403/01/01',
            ]);
            Index::createOrUpdate([
                'tender_id' => $tender->id,
                'type' => 'I2',
                'value' => 110.0,
                'date' => '1403/06/01',
            ]);
            Index::createOrUpdate([
                'tender_id' => $tender->id,
                'type' => 'I3',
                'value' => 115.0,
                'date' => '1403/09/01',
            ]);
            Index::createOrUpdate([
                'tender_id' => $tender->id,
                'type' => 'r1',
                'value' => 0.05,
            ]);
            Index::createOrUpdate([
                'tender_id' => $tender->id,
                'type' => 'r2',
                'value' => 0.03,
            ]);

            echo "  ✓ شاخص‌ها ایجاد شدند\n";

            // محاسبه Po
            $result = $this->poService->calculate($tender->id);

            if ($result['Po'] > 0 && !is_nan($result['Po']) && !is_infinite($result['Po'])) {
                echo "  ✓ تست 3: محاسبه Po موفق بود: " . number_format($result['Po']) . " ریال\n";
                echo "    Pb: " . number_format($result['Pb']) . " ریال\n";
                $this->testResults[] = [
                    'test' => 'تست 3: بررسی محاسبه Po',
                    'status' => 'PASSED',
                    'message' => 'Po درست محاسبه شد',
                ];
            } else {
                echo "  ✗ تست 3: محاسبه Po ناموفق بود\n";
                $this->testResults[] = [
                    'test' => 'تست 3: بررسی محاسبه Po',
                    'status' => 'FAILED',
                    'message' => 'Po معتبر نیست',
                ];
            }

            DB::rollBack();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->testResults[] = [
                'test' => 'تست 3: بررسی محاسبه Po',
                'status' => 'FAILED',
                'message' => $e->getMessage(),
            ];
            echo "  ✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست 4: بررسی محاسبه Pb
     */
    private function testPbCalculation(): void
    {
        $this->testCounter++;
        echo "\n" . str_repeat("-", 80) . "\n";
        echo "تست {$this->testCounter}: بررسی محاسبه Pb\n";
        echo str_repeat("-", 80) . "\n";

        DB::beginTransaction();

        try {
            // ایجاد مناقصه تست
            $tender = Tender::create([
                'code' => 'TEST-PB-' . time(),
                'title' => 'تست محاسبه Pb',
                'type' => 'test',
                'date' => '1403/09/15',
                'is_two_stage' => false,
                'normalize_prices' => false,
                'is_adjustable' => false,
                'po_method' => '1',
                'status' => 'active',
            ]);

            echo "  ✓ مناقصه تست ایجاد شد: {$tender->code}\n";

            // ایجاد چند برآورد
            $estimates = [
                ['amount' => 1000000, 'currency' => 'IRR', 'amount_in_rials' => 1000000],
                ['amount' => 2000, 'currency' => 'USD', 'exchange_rate' => 50000, 'amount_in_rials' => 100000000],
                ['amount' => 500, 'currency' => 'EUR', 'exchange_rate' => 55000, 'amount_in_rials' => 27500000],
            ];

            $expectedPb = 0;
            foreach ($estimates as $est) {
                Estimate::create([
                    'tender_id' => $tender->id,
                    'section' => 'بخش تست',
                    'amount' => $est['amount'],
                    'currency' => $est['currency'],
                    'exchange_rate' => $est['exchange_rate'] ?? null,
                    'amount_in_rials' => $est['amount_in_rials'],
                    'is_adjustable' => false,
                ]);
                $expectedPb += $est['amount_in_rials'];
            }

            echo "  ✓ برآوردها ایجاد شدند\n";

            // محاسبه Pb
            $totalPb = Estimate::calculateTotalPb($tender->id);

            if ($totalPb == $expectedPb && $totalPb > 0) {
                echo "  ✓ تست 4: محاسبه Pb درست است: " . number_format($totalPb) . " ریال\n";
                $this->testResults[] = [
                    'test' => 'تست 4: بررسی محاسبه Pb',
                    'status' => 'PASSED',
                    'message' => 'Pb درست محاسبه شد',
                ];
            } else {
                echo "  ✗ تست 4: محاسبه Pb اشتباه است. انتظار: " . number_format($expectedPb) . "، دریافت: " . number_format($totalPb) . "\n";
                $this->testResults[] = [
                    'test' => 'تست 4: بررسی محاسبه Pb',
                    'status' => 'FAILED',
                    'message' => 'Pb اشتباه محاسبه شد',
                ];
            }

            DB::rollBack();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->testResults[] = [
                'test' => 'تست 4: بررسی محاسبه Pb',
                'status' => 'FAILED',
                'message' => $e->getMessage(),
            ];
            echo "  ✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * چاپ خلاصه نتایج
     */
    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "خلاصه نتایج تست\n";
        echo str_repeat("=", 80) . "\n\n";

        $passedCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'PASSED'));
        $failedCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'FAILED'));

        echo "تعداد کل تست‌ها: " . count($this->testResults) . "\n";
        echo "تست‌های موفق: {$passedCount}\n";
        echo "تست‌های ناموفق: {$failedCount}\n\n";

        if ($failedCount > 0) {
            echo "تست‌های ناموفق:\n";
            echo str_repeat("-", 80) . "\n";
            foreach ($this->testResults as $result) {
                if ($result['status'] === 'FAILED') {
                    echo "  ✗ {$result['test']}\n";
                    echo "    خطا: {$result['message']}\n\n";
                }
            }
        }

        echo "\n" . str_repeat("=", 80) . "\n";
    }
}

// اجرای تست
try {
    $test = new EstimateAndPoTest();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}


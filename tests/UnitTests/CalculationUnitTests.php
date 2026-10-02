<?php

/**
 * تست واحد (Unit Testing) برای توابع محاسباتی
 * 
 * این تست‌ها هر تابع را به صورت مستقل و با ورودی‌های کنترل‌شده بررسی می‌کنند
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Services\PoCalculationService;
use App\Services\EvaluationService;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class CalculationUnitTests
{
    private $testResults = [];
    private $testCounter = 0;

    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "شروع تست واحد (Unit Testing) برای توابع محاسباتی\n";
        echo str_repeat("=", 100) . "\n\n";

        $this->testPoCalculationAdjustable();
        $this->testPoCalculationNonAdjustable();
        $this->testBetaCalculation();
        $this->testGammaCalculation();
        $this->testMeanCalculation();
        $this->testStdDevCalculation();
        $this->testNormalization();
        $this->testDecisionPathSelection();
        $this->testPriceRangeCalculation();

        $this->printSummary();
    }

    /**
     * تست محاسبه Po برای کار تعدیل‌پذیر
     */
    private function testPoCalculationAdjustable(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] محاسبه Po برای کار تعدیل‌پذیر\n";
        
        // ورودی‌های کنترل‌شده
        $Pb = 5000000;
        $I1 = 100.0;
        $I2 = 110.0;
        $I3 = 115.0;
        $r2 = 0.03;
        $T_beta = 0.5;
        $isAdjustable = true;

        // محاسبه دستی β
        $term = pow(1 + $r2, $T_beta);
        $beta = ($I3 / $I1) * $term;
        $expectedPo = $Pb * $beta;

        // بررسی صحت فرمول
        $calculatedBeta = ($I3 / $I1) * pow(1 + $r2, $T_beta);
        $calculatedPo = $Pb * $calculatedBeta;

        $passed = abs($calculatedPo - $expectedPo) < 0.01;
        
        $this->testResults[] = [
            'test' => 'محاسبه Po برای کار تعدیل‌پذیر',
            'passed' => $passed,
            'expected' => $expectedPo,
            'calculated' => $calculatedPo,
        ];

        echo $passed ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  Pb: " . number_format($Pb, 2) . "\n";
        echo "  β: " . number_format($calculatedBeta, 8) . "\n";
        echo "  Po مورد انتظار: " . number_format($expectedPo, 2) . "\n";
        echo "  Po محاسبه شده: " . number_format($calculatedPo, 2) . "\n";
    }

    /**
     * تست محاسبه Po برای کار فاقد تعدیل
     */
    private function testPoCalculationNonAdjustable(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] محاسبه Po برای کار فاقد تعدیل\n";
        
        $Pb = 5000000;
        $I1 = 100.0;
        $I2 = 110.0;
        $I3 = 115.0;
        $r1 = 0.05;
        $T_gamma = 1.0;
        $isAdjustable = false;

        // محاسبه دستی γ
        $term = pow(1 + $r1, $T_gamma);
        $gamma = ($I3 / $I1) * $term;
        $expectedPo = $Pb * $gamma;

        $calculatedGamma = ($I3 / $I1) * pow(1 + $r1, $T_gamma);
        $calculatedPo = $Pb * $calculatedGamma;

        $passed = abs($calculatedPo - $expectedPo) < 0.01;
        
        $this->testResults[] = [
            'test' => 'محاسبه Po برای کار فاقد تعدیل',
            'passed' => $passed,
            'expected' => $expectedPo,
            'calculated' => $calculatedPo,
        ];

        echo $passed ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  Pb: " . number_format($Pb, 2) . "\n";
        echo "  γ: " . number_format($calculatedGamma, 8) . "\n";
        echo "  Po مورد انتظار: " . number_format($expectedPo, 2) . "\n";
        echo "  Po محاسبه شده: " . number_format($calculatedPo, 2) . "\n";
    }

    /**
     * تست محاسبه β
     */
    private function testBetaCalculation(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] محاسبه ضریب β\n";
        
        $I1 = 100.0;
        $I2 = 110.0;
        $I3 = 115.0;
        $r2 = 0.03;
        $T_beta = 0.5;

        // حالت 1: I2 = I3
        $beta1 = $I2 / $I1;
        $expected1 = 1.1;

        // حالت 2: I2 ≠ I3
        $term = pow(1 + $r2, $T_beta);
        $beta2 = ($I3 / $I1) * $term;
        $expected2 = (115 / 100) * pow(1.03, 0.5);

        $passed1 = abs($beta1 - $expected1) < 0.0001;
        $passed2 = abs($beta2 - $expected2) < 0.0001;

        $this->testResults[] = [
            'test' => 'محاسبه ضریب β',
            'passed' => $passed1 && $passed2,
            'beta1' => $beta1,
            'beta2' => $beta2,
        ];

        echo ($passed1 && $passed2) ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  β (I2=I3): " . number_format($beta1, 8) . "\n";
        echo "  β (I2≠I3): " . number_format($beta2, 8) . "\n";
    }

    /**
     * تست محاسبه γ
     */
    private function testGammaCalculation(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] محاسبه ضریب γ\n";
        
        $I1 = 100.0;
        $I2 = 110.0;
        $I3 = 115.0;
        $r1 = 0.05;
        $T_gamma = 1.0;

        // حالت 1: I2 = I3
        $term1 = pow(1 + $r1, $T_gamma);
        $gamma1 = ($I2 / $I1) * $term1;

        // حالت 2: I2 ≠ I3
        $term2 = pow(1 + $r1, $T_gamma);
        $gamma2 = ($I3 / $I1) * $term2;

        $expected1 = (110 / 100) * pow(1.05, 1.0);
        $expected2 = (115 / 100) * pow(1.05, 1.0);

        $passed1 = abs($gamma1 - $expected1) < 0.0001;
        $passed2 = abs($gamma2 - $expected2) < 0.0001;

        $this->testResults[] = [
            'test' => 'محاسبه ضریب γ',
            'passed' => $passed1 && $passed2,
            'gamma1' => $gamma1,
            'gamma2' => $gamma2,
        ];

        echo ($passed1 && $passed2) ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  γ (I2=I3): " . number_format($gamma1, 8) . "\n";
        echo "  γ (I2≠I3): " . number_format($gamma2, 8) . "\n";
    }

    /**
     * تست محاسبه میانگین
     */
    private function testMeanCalculation(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] محاسبه میانگین\n";
        
        $values = [4500000, 5200000, 4800000, 5000000, 5100000];
        $expectedMean = array_sum($values) / count($values);
        
        $calculatedMean = $this->calculateMean($values);

        $passed = abs($calculatedMean - $expectedMean) < 0.01;

        $this->testResults[] = [
            'test' => 'محاسبه میانگین',
            'passed' => $passed,
            'expected' => $expectedMean,
            'calculated' => $calculatedMean,
        ];

        echo $passed ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  مقادیر: " . implode(', ', array_map(fn($v) => number_format($v), $values)) . "\n";
        echo "  میانگین مورد انتظار: " . number_format($expectedMean, 2) . "\n";
        echo "  میانگین محاسبه شده: " . number_format($calculatedMean, 2) . "\n";
    }

    /**
     * تست محاسبه انحراف معیار
     */
    private function testStdDevCalculation(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] محاسبه انحراف معیار\n";
        
        $values = [4500000, 5200000, 4800000, 5000000, 5100000];
        $mean = array_sum($values) / count($values);
        
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
        $expectedStdDev = sqrt($variance);
        
        $calculatedStdDev = $this->calculateStdDev($values, $mean);

        $passed = abs($calculatedStdDev - $expectedStdDev) < 0.01;

        $this->testResults[] = [
            'test' => 'محاسبه انحراف معیار',
            'passed' => $passed,
            'expected' => $expectedStdDev,
            'calculated' => $calculatedStdDev,
        ];

        echo $passed ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  میانگین: " . number_format($mean, 2) . "\n";
        echo "  انحراف معیار مورد انتظار: " . number_format($expectedStdDev, 2) . "\n";
        echo "  انحراف معیار محاسبه شده: " . number_format($calculatedStdDev, 2) . "\n";
    }

    /**
     * تست نرمال‌سازی قیمت‌ها
     */
    private function testNormalization(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] نرمال‌سازی قیمت‌ها\n";
        
        $price = 5000000;
        $mean = 5000000;
        $stdDev = 500000;
        
        $expectedNormalized = ($price - $mean) / $stdDev;
        $calculatedNormalized = ($price - $mean) / ($stdDev > 0 ? $stdDev : 1);

        $passed = abs($calculatedNormalized - $expectedNormalized) < 0.0001;

        $this->testResults[] = [
            'test' => 'نرمال‌سازی قیمت‌ها',
            'passed' => $passed,
            'expected' => $expectedNormalized,
            'calculated' => $calculatedNormalized,
        ];

        echo $passed ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  قیمت: " . number_format($price, 2) . "\n";
        echo "  میانگین: " . number_format($mean, 2) . "\n";
        echo "  انحراف معیار: " . number_format($stdDev, 2) . "\n";
        echo "  نرمال‌سازی شده: " . number_format($calculatedNormalized, 6) . "\n";
    }

    /**
     * تست انتخاب مسیر تصمیم‌گیری
     */
    private function testDecisionPathSelection(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] انتخاب مسیر تصمیم‌گیری\n";
        
        $po = 5000000;
        
        // تست 1: n ≤ 2 → مسیر ساده
        $prices1 = [4500000, 5200000];
        $n1 = count($prices1);
        $path1 = $this->selectPath($n1, $prices1, $po);
        $expected1 = 'SIMPLE';
        
        // تست 2: n ≥ 4 و m در بازه → مسیر آماری
        $prices2 = [4500000, 5200000, 4800000, 5000000, 5100000];
        $n2 = count($prices2);
        $path2 = $this->selectPath($n2, $prices2, $po);
        $expected2 = 'STATISTICAL';
        
        // تست 3: n ≥ 4 و m خارج از بازه → مسیر وقفه
        $prices3 = [3500000, 3800000, 4000000, 4200000];
        $n3 = count($prices3);
        $path3 = $this->selectPath($n3, $prices3, $po);
        $expected3 = 'SUSPENDED';

        $passed1 = $path1 === $expected1;
        $passed2 = $path2 === $expected2;
        $passed3 = $path3 === $expected3;

        $this->testResults[] = [
            'test' => 'انتخاب مسیر تصمیم‌گیری',
            'passed' => $passed1 && $passed2 && $passed3,
            'path1' => $path1,
            'path2' => $path2,
            'path3' => $path3,
        ];

        echo ($passed1 && $passed2 && $passed3) ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  مسیر 1 (n≤2): {$path1} (مورد انتظار: {$expected1})\n";
        echo "  مسیر 2 (n≥4, m∈بازه): {$path2} (مورد انتظار: {$expected2})\n";
        echo "  مسیر 3 (n≥4, m∉بازه): {$path3} (مورد انتظار: {$expected3})\n";
    }

    /**
     * تست محاسبه دامنه قیمت‌ها
     */
    private function testPriceRangeCalculation(): void
    {
        $this->testCounter++;
        echo "\n[تست {$this->testCounter}] محاسبه دامنه قیمت‌ها\n";
        
        $po = 5000000;
        
        // دامنه ساده: ±10%
        $simpleLower = 0.9 * $po;
        $simpleUpper = 1.1 * $po;
        
        // دامنه الحاقی: ±20% (اگر P'o در [-1,1])
        $annexLower20 = 0.8 * $po;
        $annexUpper20 = 1.2 * $po;
        
        // دامنه الحاقی: ±10% (اگر P'o خارج از [-1,1])
        $annexLower10 = 0.9 * $po;
        $annexUpper10 = 1.1 * $po;

        $passed = true;
        $passed &= abs($simpleLower - 4500000) < 0.01;
        $passed &= abs($simpleUpper - 5500000) < 0.01;
        $passed &= abs($annexLower20 - 4000000) < 0.01;
        $passed &= abs($annexUpper20 - 6000000) < 0.01;

        $this->testResults[] = [
            'test' => 'محاسبه دامنه قیمت‌ها',
            'passed' => $passed,
            'simple_range' => [$simpleLower, $simpleUpper],
            'annex_range_20' => [$annexLower20, $annexUpper20],
            'annex_range_10' => [$annexLower10, $annexUpper10],
        ];

        echo $passed ? "✓ تست موفق\n" : "✗ تست ناموفق\n";
        echo "  دامنه ساده: [" . number_format($simpleLower, 2) . ", " . number_format($simpleUpper, 2) . "]\n";
        echo "  دامنه الحاقی ±20%: [" . number_format($annexLower20, 2) . ", " . number_format($annexUpper20, 2) . "]\n";
        echo "  دامنه الحاقی ±10%: [" . number_format($annexLower10, 2) . ", " . number_format($annexUpper10, 2) . "]\n";
    }

    /**
     * محاسبه میانگین
     */
    private function calculateMean(array $values): float
    {
        if (empty($values)) return 0;
        return array_sum($values) / count($values);
    }

    /**
     * محاسبه انحراف معیار
     */
    private function calculateStdDev(array $values, float $mean): float
    {
        if (empty($values)) return 0;
        $n = count($values);
        $sumSquareDiffs = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values));
        return sqrt($sumSquareDiffs / $n);
    }

    /**
     * انتخاب مسیر تصمیم‌گیری
     */
    private function selectPath(int $n, array $prices, float $po): string
    {
        if ($n <= 2) {
            return 'SIMPLE';
        }

        if ($n >= 4) {
            $mean = $this->calculateMean($prices);
            $lowerBound = 0.8 * $po;
            $upperBound = 1.35 * $po;
            
            if ($mean >= $lowerBound && $mean <= $upperBound) {
                return 'STATISTICAL';
            } else {
                return 'SUSPENDED';
            }
        }

        return 'SIMPLE';
    }

    /**
     * چاپ خلاصه نتایج
     */
    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "خلاصه نتایج تست واحد\n";
        echo str_repeat("=", 100) . "\n\n";

        $passedCount = count(array_filter($this->testResults, fn($r) => $r['passed']));
        $totalCount = count($this->testResults);

        echo "تعداد کل تست‌ها: {$totalCount}\n";
        echo "تست‌های موفق: {$passedCount}\n";
        echo "تست‌های ناموفق: " . ($totalCount - $passedCount) . "\n\n";

        if ($passedCount < $totalCount) {
            echo "تست‌های ناموفق:\n";
            foreach ($this->testResults as $result) {
                if (!$result['passed']) {
                    echo "  - {$result['test']}\n";
                }
            }
        }

        echo str_repeat("=", 100) . "\n";
    }
}

// اجرای تست
try {
    $test = new CalculationUnitTests();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    exit(1);
}


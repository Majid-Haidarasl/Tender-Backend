<?php

/**
 * تست کامل تمام حالت‌های ممکن محاسبات مناقصات
 * این اسکریپت تمام حالت‌های ممکن را تست می‌کند و ایرادات را گزارش می‌دهد
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Services\PoCalculationService;
use App\Services\EvaluationService;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class FullTenderTest
{
    private $testResults = [];
    private $errors = [];

    public function runAllTests()
    {
        echo "=== شروع تست کامل تمام حالت‌های ممکن ===\n\n";

        // تست حالت‌های مختلف
        $testCases = $this->generateTestCases();

        foreach ($testCases as $index => $testCase) {
            echo "--- تست " . ($index + 1) . ": " . $testCase['name'] . " ---\n";
            try {
                $this->runSingleTest($testCase);
                echo "✓ تست موفق\n\n";
            } catch (\Exception $e) {
                $this->errors[] = [
                    'test' => $testCase['name'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ];
                echo "✗ خطا: " . $e->getMessage() . "\n\n";
            }
        }

        $this->printSummary();
    }

    private function generateTestCases()
    {
        return [
            // حالت 1: تک مرحله‌ای + تعدیل + روش 1
            [
                'name' => 'تک مرحله‌ای + تعدیل + روش 1',
                'is_two_stage' => false,
                'is_adjustable' => true,
                'po_method' => '1',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
            ],
            // حالت 2: تک مرحله‌ای + بدون تعدیل + روش 1
            [
                'name' => 'تک مرحله‌ای + بدون تعدیل + روش 1',
                'is_two_stage' => false,
                'is_adjustable' => false,
                'po_method' => '1',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
            ],
            // حالت 3: دو مرحله‌ای + تعدیل + روش 1
            [
                'name' => 'دو مرحله‌ای + تعدیل + روش 1',
                'is_two_stage' => true,
                'is_adjustable' => true,
                'po_method' => '1',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
            ],
            // حالت 4: دو مرحله‌ای + بدون تعدیل + روش 1
            [
                'name' => 'دو مرحله‌ای + بدون تعدیل + روش 1',
                'is_two_stage' => true,
                'is_adjustable' => false,
                'po_method' => '1',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
            ],
            // حالت 5: تک مرحله‌ای + تعدیل + روش 2
            [
                'name' => 'تک مرحله‌ای + تعدیل + روش 2',
                'is_two_stage' => false,
                'is_adjustable' => true,
                'po_method' => '2',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
            ],
            // حالت 6: دو مرحله‌ای + تعدیل + روش 2
            [
                'name' => 'دو مرحله‌ای + تعدیل + روش 2',
                'is_two_stage' => true,
                'is_adjustable' => true,
                'po_method' => '2',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
            ],
            // حالت 7: تک مرحله‌ای + تعدیل + روش 3
            [
                'name' => 'تک مرحله‌ای + تعدیل + روش 3',
                'is_two_stage' => false,
                'is_adjustable' => true,
                'po_method' => '3',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
            ],
            // حالت 8: دو مرحله‌ای + تعدیل + روش 3
            [
                'name' => 'دو مرحله‌ای + تعدیل + روش 3',
                'is_two_stage' => true,
                'is_adjustable' => true,
                'po_method' => '3',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
            ],
        ];
    }

    private function runSingleTest($testCase)
    {
        DB::beginTransaction();
        try {
            // 1. ایجاد مناقصه
            $tender = $this->createTender($testCase);
            echo "  ✓ مناقصه ایجاد شد: {$tender->id}\n";

            // 2. ثبت برآورد اولیه (P_b)
            $this->createEstimates($tender->id);
            echo "  ✓ برآورد اولیه ثبت شد\n";

            // 3. ثبت شاخص‌ها
            $this->createIndices($tender->id, $testCase['po_method']);
            echo "  ✓ شاخص‌ها ثبت شدند\n";

            // 4. محاسبه P_o
            $poService = new PoCalculationService();
            $poResult = $poService->calculate($tender->id);
            $tender->po = $poResult['Po'];
            $tender->save();
            // ثبت زمان محاسبه
            Tender::setPoCalculatedAt($tender->id);
            echo "  ✓ P_o محاسبه شد: " . number_format($poResult['Po'], 2) . " ریال\n";
            echo "    - روش: " . $poResult['method'] . "\n";
            if (isset($poResult['beta'])) {
                echo "    - β: " . number_format($poResult['beta'], 6) . "\n";
            }
            if (isset($poResult['gamma'])) {
                echo "    - γ: " . number_format($poResult['gamma'], 6) . "\n";
            }

            // 5. ثبت مناقصه‌گران
            $this->createBidders($tender->id, $testCase['is_two_stage']);
            echo "  ✓ مناقصه‌گران ثبت شدند\n";

            // 6. انجام ارزیابی مالی
            $evalService = new EvaluationService();
            $evalResult = $evalService->evaluate($tender->id);
            echo "  ✓ ارزیابی مالی انجام شد\n";
            echo "    - مسیر: " . ($evalResult['path'] ?? 'N/A') . "\n";
            echo "    - عمل: " . ($evalResult['action'] ?? 'N/A') . "\n";
            echo "    - میانگین: " . number_format($evalResult['mean'], 2) . " ریال\n";
            echo "    - انحراف معیار: " . number_format($evalResult['std_dev'], 2) . " ریال\n";
            echo "    - محدوده نهایی: [" . number_format($evalResult['ranges']['final_lower'], 2) . ", " . number_format($evalResult['ranges']['final_upper'], 2) . "]\n";
            if (isset($evalResult['ranges']['m_prime_o']) && $evalResult['ranges']['m_prime_o'] > 0) {
                echo "    - m'_o: " . number_format($evalResult['ranges']['m_prime_o'], 2) . " ریال\n";
                echo "    - s'_o: " . number_format($evalResult['ranges']['s_prime_o'], 2) . " ریال\n";
            }

            // 7. بررسی برندگان
            $winners = array_filter($evalResult['results'], function($r) {
                return ($r['is_winner_first'] ?? false) || ($r['is_winner_second'] ?? false);
            });

            if (empty($winners)) {
                echo "  ⚠ هیچ برنده‌ای در محدوده قیمت متناسب یافت نشد\n";
                if ($evalResult['action'] === 'CANCELLED_M62') {
                    echo "    → مناقصه به دلیل عدم احراز شرط ماده ۶-۲ لغو شد\n";
                } elseif ($evalResult['action'] === 'NO_VALID_BIDS') {
                    echo "    → هیچ پیشنهاد معتبری در دامنه قیمت متناسب وجود ندارد\n";
                }
            } else {
                foreach ($winners as $winner) {
                    if ($winner['is_winner_first'] ?? false) {
                        echo "  ✓ برنده اول: {$winner['bidder_name']} - " . number_format($winner['normalized_price'], 2) . " ریال\n";
                        echo "    (P_i: " . number_format($winner['original_price'], 2) . ", A_i: " . number_format($winner['technical_score'], 2) . ")\n";
                    }
                    if ($winner['is_winner_second'] ?? false) {
                        echo "  ✓ برنده دوم: {$winner['bidder_name']} - " . number_format($winner['normalized_price'], 2) . " ریال\n";
                        echo "    (P_i: " . number_format($winner['original_price'], 2) . ", A_i: " . number_format($winner['technical_score'], 2) . ")\n";
                    }
                }
            }
            
            // 8. نمایش رتبه‌بندی کامل
            echo "\n  رتبه‌بندی کامل:\n";
            foreach ($evalResult['results'] as $result) {
                $status = '';
                if ($result['is_winner_first'] ?? false) {
                    $status = ' [برنده اول]';
                } elseif ($result['is_winner_second'] ?? false) {
                    $status = ' [برنده دوم]';
                } elseif ($result['in_final_range'] ?? false) {
                    $status = ' [در محدوده]';
                } else {
                    $status = ' [خارج از محدوده]';
                }
                echo "    {$result['rank']}. {$result['bidder_name']}: P'_i = " . number_format($result['normalized_price'], 2) . $status . "\n";
            }

            // 8. بررسی صحت محاسبات
            $this->validateCalculations($tender, $evalResult, $testCase);

            $this->testResults[] = [
                'test' => $testCase['name'],
                'tender_id' => $tender->id,
                'success' => true,
                'po' => $poResult['Po'],
                'winners_count' => count($winners),
            ];

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function createTender($testCase)
    {
        return Tender::create([
            'code' => 'TEST-' . time() . '-' . rand(1000, 9999),
            'title' => 'مناقصه تست - ' . $testCase['name'],
            'type' => 'پیمانکاری',
            'date' => now()->format('Y-m-d'),
            'is_two_stage' => $testCase['is_two_stage'],
            'is_adjustable' => $testCase['is_adjustable'],
            'po_method' => $testCase['po_method'],
            'tgamma' => $testCase['tgamma'],
            'tbeta' => $testCase['tbeta'],
            'delta' => $testCase['delta'],
            'a_max' => 100,
            'status' => 'active',
        ]);
    }

    private function createEstimates($tenderId)
    {
        // ایجاد 3 برآورد اولیه
        $estimates = [
            [
                'tender_id' => $tenderId,
                'section' => 'بخش 1',
                'amount' => 1000000000, // 1 میلیارد
                'currency' => 'IRR',
                'exchange_rate' => 1,
                'amount_in_rials' => 1000000000,
                'is_adjustable' => true,
            ],
            [
                'tender_id' => $tenderId,
                'section' => 'بخش 2',
                'amount' => 2000000000, // 2 میلیارد
                'currency' => 'IRR',
                'exchange_rate' => 1,
                'amount_in_rials' => 2000000000,
                'is_adjustable' => true,
            ],
            [
                'tender_id' => $tenderId,
                'section' => 'بخش 3',
                'amount' => 1500000000, // 1.5 میلیارد
                'currency' => 'IRR',
                'exchange_rate' => 1,
                'amount_in_rials' => 1500000000,
                'is_adjustable' => false,
            ],
        ];

        foreach ($estimates as $estimate) {
            Estimate::create($estimate);
        }
    }

    private function createIndices($tenderId, $method)
    {
        if ($method === '1') {
            // روش اول: شاخص‌های تورم
            $indices = [
                ['tender_id' => $tenderId, 'type' => 'I1', 'value' => 100],
                ['tender_id' => $tenderId, 'type' => 'I2', 'value' => 120],
                ['tender_id' => $tenderId, 'type' => 'I3', 'value' => 125],
                ['tender_id' => $tenderId, 'type' => 'r1', 'value' => 0.15],
                ['tender_id' => $tenderId, 'type' => 'r2', 'value' => 0.20],
            ];
        } elseif ($method === '2') {
            // روش دوم: میانگین درصد تغییرات
            $indices = [
                ['tender_id' => $tenderId, 'type' => 'F1', 'value' => 100],
                ['tender_id' => $tenderId, 'type' => 'f1', 'value' => 100],
                ['tender_id' => $tenderId, 'type' => 'f2', 'value' => 105],
                ['tender_id' => $tenderId, 'type' => 'f3', 'value' => 110],
                ['tender_id' => $tenderId, 'type' => 'f4', 'value' => 115],
                ['tender_id' => $tenderId, 'type' => 'f5', 'value' => 120],
                ['tender_id' => $tenderId, 'type' => 'f6', 'value' => 125],
                ['tender_id' => $tenderId, 'type' => 'f7', 'value' => 130],
                ['tender_id' => $tenderId, 'type' => 'f8', 'value' => 135],
                ['tender_id' => $tenderId, 'type' => 'f9', 'value' => 140],
                ['tender_id' => $tenderId, 'type' => 'z', 'value' => 2],
            ];
        } else {
            // روش سوم: میانگین تفاضل عددی
            $indices = [
                ['tender_id' => $tenderId, 'type' => 'F1', 'value' => 100],
                ['tender_id' => $tenderId, 'type' => 'f1', 'value' => 100],
                ['tender_id' => $tenderId, 'type' => 'f2', 'value' => 105],
                ['tender_id' => $tenderId, 'type' => 'f3', 'value' => 110],
                ['tender_id' => $tenderId, 'type' => 'f4', 'value' => 115],
                ['tender_id' => $tenderId, 'type' => 'f5', 'value' => 120],
                ['tender_id' => $tenderId, 'type' => 'f6', 'value' => 125],
                ['tender_id' => $tenderId, 'type' => 'f7', 'value' => 130],
                ['tender_id' => $tenderId, 'type' => 'f8', 'value' => 135],
                ['tender_id' => $tenderId, 'type' => 'f9', 'value' => 140],
                ['tender_id' => $tenderId, 'type' => 'z', 'value' => 2],
            ];
        }

        // استفاده از bulkUpsert برای جلوگیری از duplicate
        Index::bulkUpsert($indices);
    }

    private function createBidders($tenderId, $isTwoStage)
    {
        // ایجاد 5 مناقصه‌گر با قیمت‌های مختلف
        $bidders = [
            [
                'tender_id' => $tenderId,
                'name' => 'شرکت الف',
                'price' => 4500000000, // 4.5 میلیارد
                'technical_score' => $isTwoStage ? 85 : 100,
                'is_qualified' => true,
            ],
            [
                'tender_id' => $tenderId,
                'name' => 'شرکت ب',
                'price' => 5000000000, // 5 میلیارد
                'technical_score' => $isTwoStage ? 90 : 100,
                'is_qualified' => true,
            ],
            [
                'tender_id' => $tenderId,
                'name' => 'شرکت ج',
                'price' => 5500000000, // 5.5 میلیارد
                'technical_score' => $isTwoStage ? 95 : 100,
                'is_qualified' => true,
            ],
            [
                'tender_id' => $tenderId,
                'name' => 'شرکت د',
                'price' => 6000000000, // 6 میلیارد
                'technical_score' => $isTwoStage ? 80 : 100,
                'is_qualified' => true,
            ],
            [
                'tender_id' => $tenderId,
                'name' => 'شرکت ه',
                'price' => 6500000000, // 6.5 میلیارد
                'technical_score' => $isTwoStage ? 100 : 100,
                'is_qualified' => true,
            ],
        ];

        foreach ($bidders as $bidder) {
            Bidder::create($bidder);
        }
    }

    private function validateCalculations($tender, $evalResult, $testCase)
    {
        $errors = [];

        // بررسی 1: P_o باید مثبت باشد
        if ($tender->po <= 0) {
            $errors[] = "P_o باید مقدار مثبت داشته باشد";
        }

        // بررسی 2: محدوده قیمت باید معتبر باشد
        if ($evalResult['ranges']['final_lower'] >= $evalResult['ranges']['final_upper']) {
            $errors[] = "محدوده قیمت نامعتبر است (P_Lower >= P_Upper)";
        }

        // بررسی 3: برای مناقصه دو مرحله‌ای، قیمت‌های نرمالیزه باید متفاوت باشند
        if ($testCase['is_two_stage']) {
            $normalizedPrices = array_column($evalResult['results'], 'normalized_price');
            $uniquePrices = array_unique($normalizedPrices);
            if (count($uniquePrices) === 1 && count($normalizedPrices) > 1) {
                $errors[] = "برای مناقصه دو مرحله‌ای، قیمت‌های نرمالیزه باید متفاوت باشند";
            }
        }

        // بررسی 4: برنده اول باید در محدوده نهایی باشد
        $winnerFirst = array_filter($evalResult['results'], function($r) {
            return $r['is_winner_first'];
        });
        if (!empty($winnerFirst)) {
            $winner = reset($winnerFirst);
            if (!$winner['in_final_range']) {
                $errors[] = "برنده اول باید در محدوده نهایی باشد";
            }
        }

        if (!empty($errors)) {
            throw new \Exception("خطاهای اعتبارسنجی: " . implode(", ", $errors));
        }
    }

    private function printSummary()
    {
        echo "\n=== خلاصه نتایج تست ===\n";
        echo "تعداد تست‌های موفق: " . count(array_filter($this->testResults, fn($r) => $r['success'])) . "\n";
        echo "تعداد تست‌های ناموفق: " . count($this->errors) . "\n\n";

        if (!empty($this->errors)) {
            echo "=== خطاها ===\n";
            foreach ($this->errors as $error) {
                echo "تست: {$error['test']}\n";
                echo "خطا: {$error['error']}\n\n";
            }
        }
    }
}

// اجرای تست
$test = new FullTenderTest();
$test->runAllTests();


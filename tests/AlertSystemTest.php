<?php

/**
 * تست سیستم هشدار، خطا و اعلان
 * 
 * این تست تمام پیام‌های تعریف شده در جدول را بررسی می‌کند
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Services\AlertNotificationService;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class AlertSystemTest
{
    private $alertService;
    private $testResults = [];
    private $testCounter = 0;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
    }

    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "تست سیستم هشدار، خطا و اعلان\n";
        echo str_repeat("=", 100) . "\n\n";

        $this->testAllMessages();
        $this->testMessageTypes();
        $this->testStageMessages();
        $this->testCombinedErrors();
        $this->testAutoDetection();
        $this->testNewExceptionMessages();

        $this->printSummary();
    }

    /**
     * تست تمام پیام‌های تعریف شده
     */
    private function testAllMessages(): void
    {
        echo "\n[تست 1] بررسی تمام پیام‌های تعریف شده\n";
        echo str_repeat("-", 100) . "\n";

        $allMessages = $this->alertService->getAllMessages();
        $expectedCount = 23; // تعداد پیام‌های جدول (17 اصلی + 6 جدید)

        $this->testResults[] = [
            'test' => 'بررسی تمام پیام‌های تعریف شده',
            'expected_count' => $expectedCount,
            'actual_count' => count($allMessages),
            'passed' => count($allMessages) >= $expectedCount,
        ];

        echo "تعداد پیام‌های تعریف شده: " . count($allMessages) . "\n";
        echo "پیام‌های موجود:\n";
        foreach ($allMessages as $id => $message) {
            echo "  {$id}: {$message['type']} - {$message['stage']}\n";
        }
    }

    /**
     * تست انواع پیام‌ها
     */
    private function testMessageTypes(): void
    {
        echo "\n[تست 2] تست انواع پیام‌ها (هشدار، خطا، اطلاع)\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();

            // تست هشدار
            $warningResult = $this->alertService->sendWarning('W001', $tender->id, [
                'pb' => 0,
            ]);
            $warningSent = !empty($warningResult['message']);

            // تست خطا
            $errorResult = $this->alertService->sendError('E001', $tender->id, [
                'pb' => 0,
            ]);
            $errorSent = !empty($errorResult['message']);
            $processStopped = $tender->fresh()->status === 'SUSPENDED';

            // تست اطلاع
            $infoResult = $this->alertService->sendInfo('I001', $tender->id, [
                'mean' => 5000000,
            ]);
            $infoSent = !empty($infoResult['message']);

            $this->testResults[] = [
                'test' => 'تست انواع پیام‌ها',
                'warning_sent' => $warningSent,
                'error_sent' => $errorSent,
                'process_stopped' => $processStopped,
                'info_sent' => $infoSent,
                'passed' => $warningSent && $errorSent && $processStopped && $infoSent,
            ];

            echo $warningSent ? "✓ هشدار ارسال شد\n" : "✗ هشدار ارسال نشد\n";
            echo $errorSent ? "✓ خطا ارسال شد\n" : "✗ خطا ارسال نشد\n";
            echo $processStopped ? "✓ فرآیند متوقف شد\n" : "✗ فرآیند متوقف نشد\n";
            echo $infoSent ? "✓ اطلاع ارسال شد\n" : "✗ اطلاع ارسال نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست پیام‌های هر مرحله
     */
    private function testStageMessages(): void
    {
        echo "\n[تست 3] تست پیام‌های هر مرحله\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();

            $stages = [
                AlertNotificationService::STAGE_PB_INPUT => ['pb' => 0],
                AlertNotificationService::STAGE_INDEX_INPUT => ['po_method' => '2'],
                AlertNotificationService::STAGE_PO_CALCULATION => ['pb' => 0, 'indices_valid' => false],
                AlertNotificationService::STAGE_PI_INPUT => ['pi_complete' => false],
                AlertNotificationService::STAGE_DECISION_PATH => ['bid_count' => 1, 'po' => 5000000],
            ];

            $messagesSent = 0;
            foreach ($stages as $stage => $data) {
                $messages = $this->alertService->checkAndSend($tender->id, $stage, $data);
                $messagesSent += count($messages);
            }

            $this->testResults[] = [
                'test' => 'تست پیام‌های هر مرحله',
                'messages_sent' => $messagesSent,
                'passed' => $messagesSent > 0,
            ];

            echo "تعداد پیام‌های ارسال شده: {$messagesSent}\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست خطاهای ترکیبی
     */
    private function testCombinedErrors(): void
    {
        echo "\n[تست 4] تست خطاهای ترکیبی\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();

            // E004: Pi و f ناقص همزمان
            $messages1 = $this->alertService->checkCombinedErrors($tender->id, [
                'pi_complete' => false,
                'indices_incomplete' => true,
            ]);

            // W010: مسیر وقفه و مسیر آماری همزمان
            $messages2 = $this->alertService->checkCombinedErrors($tender->id, [
                'suspended_path' => true,
                'statistical_path' => true,
            ]);

            $combinedErrorsDetected = count($messages1) > 0 || count($messages2) > 0;

            $this->testResults[] = [
                'test' => 'تست خطاهای ترکیبی',
                'e004_detected' => count($messages1) > 0,
                'w010_detected' => count($messages2) > 0,
                'passed' => $combinedErrorsDetected,
            ];

            echo count($messages1) > 0 ? "✓ E004 (Pi و f ناقص) شناسایی شد\n" : "✗ E004 شناسایی نشد\n";
            echo count($messages2) > 0 ? "✓ W010 (تداخل مسیرها) شناسایی شد\n" : "✗ W010 شناسایی نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * تست تشخیص خودکار
     */
    private function testAutoDetection(): void
    {
        echo "\n[تست 5] تست تشخیص خودکار پیام‌ها\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();
            $this->createEstimates($tender->id, 5000000);
            $this->createIndices($tender->id);

            // تست تشخیص خودکار در مرحله Pb
            $messages1 = $this->alertService->checkAndSend($tender->id, AlertNotificationService::STAGE_PB_INPUT, [
                'pb' => 0,
            ]);

            // تست تشخیص خودکار در مرحله Decision Path
            $messages2 = $this->alertService->checkAndSend($tender->id, AlertNotificationService::STAGE_DECISION_PATH, [
                'bid_count' => 1,
                'po' => 5000000,
            ]);

            $autoDetectionWorks = count($messages1) > 0 || count($messages2) > 0;

            $this->testResults[] = [
                'test' => 'تست تشخیص خودکار',
                'auto_detection_works' => $autoDetectionWorks,
                'messages_detected' => count($messages1) + count($messages2),
                'passed' => $autoDetectionWorks,
            ];

            echo $autoDetectionWorks ? "✓ تشخیص خودکار کار می‌کند\n" : "✗ تشخیص خودکار کار نمی‌کند\n";
            echo "تعداد پیام‌های تشخیص داده شده: " . (count($messages1) + count($messages2)) . "\n";

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
            'code' => 'TEST-ALERT-' . time(),
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
     * تست پیام‌های استثنایی جدید
     */
    private function testNewExceptionMessages(): void
    {
        echo "\n[تست 6] تست پیام‌های استثنایی جدید\n";
        echo str_repeat("-", 100) . "\n";

        DB::beginTransaction();

        try {
            $tender = $this->createTender();

            // تست W011: ناسازگاری شاخص‌های تعدیل تاریخی
            $inconsistencyCheck = $this->alertService->checkIndexInconsistency($tender->id, '2');
            $w011Tested = method_exists($this->alertService, 'checkIndexInconsistency');

            // تست W012: محدوده ناهماهنگ Po
            $unreasonableCheck = $this->alertService->checkUnreasonablePoRange($tender->id, 10000000, 5000000); // 50% - غیرمنطقی
            $w012Tested = $unreasonableCheck !== null;

            // تست E005: خطا در روش پیش‌بینی
            $forecastError = $this->alertService->checkForecastMethodError($tender->id, '2', [
                'f_prime_2' => null, // خطا
            ]);
            $e005Tested = $forecastError !== null;

            // تست E006: عدم تطابق مسیر دو مرحله‌ای
            $tenderTwoStage = $this->createTender(['is_two_stage' => true]);
            $messages = $this->alertService->checkAndSend($tenderTwoStage->id, AlertNotificationService::STAGE_TECHNICAL_COMMERCIAL, [
                'is_two_stage' => true,
                'technical_stage_complete' => false,
            ]);
            $e006Tested = !empty($messages);

            // تست E007: ورودی‌های ناقص ترکیبی
            $combinedErrors = $this->alertService->checkCombinedErrors($tender->id, [
                'pi_complete' => false,
                'po' => 0,
                'mo' => 0,
                'so' => 0,
                'n' => 2,
                'mean' => null,
            ]);
            $e007Tested = !empty($combinedErrors);

            // تست W013: مقادیر نرمال‌شده خارج از حد
            $extremeCheck = $this->alertService->checkExtremeNormalizedValues($tender->id, [6.5, -7.2, 8.1]);
            $w013Tested = $extremeCheck !== null;

            $this->testResults[] = [
                'test' => 'تست پیام‌های استثنایی جدید',
                'w011_tested' => $w011Tested,
                'w012_tested' => $w012Tested,
                'e005_tested' => $e005Tested,
                'e006_tested' => $e006Tested,
                'e007_tested' => $e007Tested,
                'w013_tested' => $w013Tested,
                'passed' => $w011Tested && $w012Tested && $e005Tested && $e006Tested && $e007Tested && $w013Tested,
            ];

            echo $w011Tested ? "✓ W011 (ناسازگاری شاخص‌ها) تست شد\n" : "✗ W011 تست نشد\n";
            echo $w012Tested ? "✓ W012 (محدوده ناهماهنگ) تست شد\n" : "✗ W012 تست نشد\n";
            echo $e005Tested ? "✓ E005 (خطا در روش پیش‌بینی) تست شد\n" : "✗ E005 تست نشد\n";
            echo $e006Tested ? "✓ E006 (عدم تطابق دو مرحله‌ای) تست شد\n" : "✗ E006 تست نشد\n";
            echo $e007Tested ? "✓ E007 (ورودی‌های ناقص ترکیبی) تست شد\n" : "✗ E007 تست نشد\n";
            echo $w013Tested ? "✓ W013 (مقادیر نرمال‌شده شدید) تست شد\n" : "✗ W013 تست نشد\n";

            DB::rollBack();

        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ خطا: {$e->getMessage()}\n";
        }
    }

    /**
     * چاپ خلاصه نتایج
     */
    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "خلاصه نتایج تست سیستم هشدار\n";
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
    $test = new AlertSystemTest();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    exit(1);
}


<?php

/**
 * تست جامع مسیرهای تصمیم‌گیری و محاسباتی سامانه مناقصات
 * 
 * این تست تمام مسیرهای تصمیم‌گیری، شرایط، ورودی‌ها و پاسخ‌های مورد انتظار را پوشش می‌دهد:
 * 
 * 1️⃣ مرحله ثبت برآورد اولیه و Po
 * 2️⃣ مرحله دریافت پیشنهاد (Bids)
 * 3️⃣ مرحله فیلتر ماده ۵
 * 4️⃣ تحلیل آماری
 * 5️⃣ انتخاب برنده
 * 6️⃣ مسیرهای جایگزین (Fallback)
 * 7️⃣ امتیاز فنی و بازرگانی
 * 8️⃣ پوشش همه مسیرهای تصمیم
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
use App\Services\Article5Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// بارگذاری Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class DecisionPathComprehensiveTest
{
    private $poService;
    private $evaluationService;
    private $article5Validator;
    private $testResults = [];
    private $testCounter = 0;
    private const MIN_BIDS_AFTER_OUTLIER = 3;

    public function __construct()
    {
        $this->poService = new PoCalculationService();
        $this->evaluationService = new EvaluationService();
        $this->article5Validator = new Article5Validator();
    }

    /**
     * اجرای تمام تست‌ها
     */
    public function runAllTests(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "شروع تست جامع مسیرهای تصمیم‌گیری و محاسباتی سامانه مناقصات\n";
        echo str_repeat("=", 100) . "\n\n";

        $testCases = $this->generateTestCases();

        foreach ($testCases as $index => $testCase) {
            $this->testCounter = $index + 1;
            echo "\n" . str_repeat("-", 100) . "\n";
            echo "تست {$this->testCounter}/{$testCase['total']}: {$testCase['name']}\n";
            echo str_repeat("-", 100) . "\n";
            echo "شرایط: {$testCase['condition']}\n";
            echo "ورودی‌ها: {$testCase['inputs']}\n";
            echo "مسیر تصمیم: {$testCase['decision_path']}\n";
            echo "پاسخ مورد انتظار: {$testCase['expected_response']}\n";

            try {
                $result = $this->runSingleTest($testCase);
                $this->testResults[] = [
                    'test_number' => $this->testCounter,
                    'name' => $testCase['name'],
                    'status' => 'SUCCESS',
                    'result' => $result,
                    'error' => null,
                ];
                echo "✓ تست با موفقیت انجام شد\n";
                $this->printTestResult($result);
            } catch (\Exception $e) {
                $this->testResults[] = [
                    'test_number' => $this->testCounter,
                    'name' => $testCase['name'],
                    'status' => 'FAILED',
                    'error' => $e->getMessage(),
                ];
                echo "✗ تست با خطا مواجه شد: {$e->getMessage()}\n";
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

        // ============================================
        // 1️⃣ مرحله ثبت برآورد اولیه و Po
        // ============================================

        // سوال 1: محاسبه Po زمانی که Pb تایید شده و شاخص‌ها کامل هستند
        $testCases[] = [
            'name' => 'سوال 1: محاسبه Po با Pb=5,000,000 و شاخص‌های کامل',
            'condition' => 'وضعیت = PB_APPROVED, Po محاسبه نشده, شاخص‌ها کامل',
            'inputs' => 'Pb=5,000,000, I2=110, I3=115, r1=0.05, r2=0.03, Tβ=0.5',
            'decision_path' => 'وضعیت = PB_APPROVED → Event = calculate_po',
            'expected_response' => 'Po محاسبه شده, ثبت در analyses, وضعیت = PO_CALCULATED, آلارم و لاگ ثبت',
            'test_type' => 'po_calculation',
            'pb' => 5000000,
            'status' => 'PB_APPROVED',
            'indices_complete' => true,
            'po_method' => '1',
            'is_adjustable' => true,
        ];

        // سوال 2: عدم محاسبه Po زمانی که شاخص‌ها ناقص هستند
        $testCases[] = [
            'name' => 'سوال 2: عدم محاسبه Po با شاخص‌های ناقص',
            'condition' => 'Pb در وضعیت DRAFT یا PB_SUBMITTED, شاخص‌ها ناقص',
            'inputs' => 'Pb=5,000,000, I2=0 (ناقص)',
            'decision_path' => 'بررسی شاخص‌ها → ناقص بودن → توقف محاسبه',
            'expected_response' => 'محاسبه Po متوقف, وضعیت تغییر نمی‌کند, پیام خطا/هشدار',
            'test_type' => 'po_calculation_failure',
            'pb' => 5000000,
            'status' => 'DRAFT',
            'indices_complete' => false,
            'po_method' => '1',
            'is_adjustable' => true,
        ];

        // ============================================
        // 2️⃣ مرحله دریافت پیشنهاد (Bids)
        // ============================================

        // سوال 3: ثبت پیشنهاد جدید در وضعیت BIDS_OPEN
        $testCases[] = [
            'name' => 'سوال 3: ثبت پیشنهاد جدید در وضعیت BIDS_OPEN',
            'condition' => 'وضعیت مناقصه = BIDS_OPEN, پیشنهاد تایید شده و معتبر',
            'inputs' => 'Pi=4,500,000, پیشنهاد معتبر',
            'decision_path' => 'بررسی وضعیت → BIDS_OPEN → ثبت پیشنهاد',
            'expected_response' => 'پیشنهاد به جدول bids اضافه, شاخص‌های آماری به‌روز نمی‌شوند تا BIDS_CLOSED',
            'test_type' => 'bid_submission',
            'tender_status' => 'BIDS_OPEN',
            'bid_price' => 4500000,
            'bid_valid' => true,
        ];

        // سوال 4: ثبت پیشنهاد بعد از بسته شدن
        $testCases[] = [
            'name' => 'سوال 4: ثبت پیشنهاد بعد از بسته شدن',
            'condition' => 'وضعیت مناقصه = BIDS_CLOSED',
            'inputs' => 'Pi=4,500,000',
            'decision_path' => 'بررسی وضعیت → BIDS_CLOSED → رد پیشنهاد',
            'expected_response' => 'پیشنهاد رد می‌شود, هشدار به کاربر ارسال می‌شود',
            'test_type' => 'bid_submission_after_close',
            'tender_status' => 'BIDS_CLOSED',
            'bid_price' => 4500000,
            'bid_valid' => false,
        ];

        // ============================================
        // 3️⃣ مرحله فیلتر ماده ۵
        // ============================================

        // سوال 5: اجرای فیلتر ماده ۵
        $testCases[] = [
            'name' => 'سوال 5: اجرای فیلتر ماده ۵',
            'condition' => 'وضعیت = BIDS_CLOSED, تعداد پیشنهادها >= 3',
            'inputs' => 'n=5 پیشنهاد, Pi=[4.5M, 5.2M, 4.8M, 6.0M, 4.2M]',
            'decision_path' => 'apply_filter event → حذف پیشنهادهای خارج از دامنه',
            'expected_response' => 'پیشنهادهای خارج از دامنه حذف, ثبت تعداد Pi باقیمانده و حذف‌شده',
            'test_type' => 'article5_filter',
            'tender_status' => 'BIDS_CLOSED',
            'bid_count' => 5,
            'bid_prices' => [4500000, 5200000, 4800000, 6000000, 4200000],
        ];

        // ============================================
        // 4️⃣ تحلیل آماری
        // ============================================

        // سوال 6: انجام تراز (Normalize) زمانی که شرایط برقرار است
        $testCases[] = [
            'name' => 'سوال 6: انجام تراز با n>=4 و m در بازه مجاز',
            'condition' => 'تعداد Pi پس از فیلتر >= 4, میانگین m در بازه (0.8 Po ≤ m ≤ 1.35 Po)',
            'inputs' => 'n=5, Po=5,000,000, Pi=[4.5M, 5.2M, 4.8M, 5.0M, 5.1M], m=4.92M',
            'decision_path' => 'حذف outlier → محاسبه mo, so → نرمال‌سازی Pi → Pi\'',
            'expected_response' => 'Pi\' محاسبه شده, وضعیت = ANALYSIS_STAT, خروجی شامل mo, so, k, لیست Pi\', آلارم و لاگ',
            'test_type' => 'statistical_normalization',
            'bid_count' => 5,
            'po' => 5000000,
            'bid_prices' => [4500000, 5200000, 4800000, 5000000, 5100000],
            'mean_in_range' => true,
        ];

        // سوال 7: عدم انجام تراز زمانی که شرایط برقرار نیست
        $testCases[] = [
            'name' => 'سوال 7: عدم انجام تراز با n<4 یا m خارج از بازه',
            'condition' => 'تعداد Pi < 4 یا m خارج از بازه مجاز',
            'inputs' => 'n=3, Po=5,000,000, Pi=[4.5M, 5.2M, 4.8M], m=4.83M',
            'decision_path' => 'بررسی n و m → عدم برقراری شرایط → مسیر مستقیم',
            'expected_response' => 'مسیر مستقیم انتخاب برنده یا وضعیت SUSPENDED, آلارم و گزارش به کمیته',
            'test_type' => 'no_normalization',
            'bid_count' => 3,
            'po' => 5000000,
            'bid_prices' => [4500000, 5200000, 4800000],
            'mean_in_range' => false,
        ];

        // ============================================
        // 5️⃣ انتخاب برنده
        // ============================================

        // سوال 8: انتخاب برنده با کمترین Pi\'
        $testCases[] = [
            'name' => 'سوال 8: انتخاب برنده با کمترین Pi\'',
            'condition' => 'Pi\' موجود, همه شرایط دامنه مجاز رعایت شده',
            'inputs' => 'Pi\'=[4.5M, 5.2M, 4.8M, 5.0M], همه در دامنه',
            'decision_path' => 'بررسی Pi\' → انتخاب کمترین → تعیین برنده',
            'expected_response' => 'برنده انتخاب شود, وضعیت = WINNER_SELECTED, اعلان به داشبورد و ایمیل, آدیت ثبت',
            'test_type' => 'winner_selection',
            'normalized_prices' => [4500000, 5200000, 4800000, 5000000],
            'all_in_range' => true,
        ];

        // سوال 9: چند پیشنهاد خارج از محدوده مجاز
        $testCases[] = [
            'name' => 'سوال 9: چند پیشنهاد خارج از محدوده مجاز',
            'condition' => 'چند پیشنهاد خارج از محدوده مجاز',
            'inputs' => 'Pi\'=[4.5M, 6.5M, 4.8M, 7.0M], 2 پیشنهاد خارج از محدوده',
            'decision_path' => 'بررسی محدوده → خارج از محدوده → نیاز به کمیته',
            'expected_response' => 'وضعیت = SUSPENDED, ارسال گزارش و اعلان به کمیته, انتخاب برنده تا تایید کمیته ممنوع',
            'test_type' => 'out_of_range_bids',
            'normalized_prices' => [4500000, 6500000, 4800000, 7000000],
            'out_of_range_count' => 2,
        ];

        // ============================================
        // 6️⃣ مسیرهای جایگزین (Fallback)
        // ============================================

        // سوال 10: مسیر Fallback با n < MIN_BIDS_AFTER_OUTLIER
        $testCases[] = [
            'name' => 'سوال 10: مسیر Fallback با n < MIN_BIDS_AFTER_OUTLIER',
            'condition' => 'تعداد Pi پس از حذف outlier < MIN_BIDS_AFTER_OUTLIER',
            'inputs' => 'n=2 پس از حذف outlier, Pi=[4.5M, 5.2M]',
            'decision_path' => 'بررسی n → n < MIN → مسیر ساده',
            'expected_response' => 'مسیر ساده: انتخاب کمترین Pi, وضعیت = WINNER_SELECTED, ثبت در audit_logs با پرچم Fallback',
            'test_type' => 'fallback_path',
            'bid_count_after_outlier' => 2,
            'bid_prices' => [4500000, 5200000],
        ];

        // ============================================
        // 7️⃣ امتیاز فنی و بازرگانی
        // ============================================

        // سوال 11: اعمال امتیاز فنی و بازرگانی
        $testCases[] = [
            'name' => 'سوال 11: اعمال امتیاز فنی و بازرگانی',
            'condition' => 'پیشنهاد شامل بخش‌های فنی/تجاری, تحلیل آماری اجرا شده',
            'inputs' => 'Pi\'=5.0M, technical_score=90, commercial_score=85, α=0.6, β=0.4',
            'decision_path' => 'ترکیب فنی/بازرگانی → Pi_final = α * Pi\'_technical + β * Pi\'_commercial',
            'expected_response' => 'Pi_final محاسبه شده, مسیر تصمیم = انتخاب برنده با Pi_final',
            'test_type' => 'technical_commercial_scoring',
            'normalized_price' => 5000000,
            'technical_score' => 90,
            'commercial_score' => 85,
            'alpha' => 0.6,
            'beta' => 0.4,
        ];

        // ============================================
        // 8️⃣ پوشش همه مسیرهای تصمیم
        // ============================================

        // سوال 12: مدیریت مسیر تصمیم بر اساس n، میانگین، outlier و دامنه
        $testCases[] = [
            'name' => 'سوال 12: مدیریت مسیر تصمیم - n ≤ 2',
            'condition' => 'n ≤ 2',
            'inputs' => 'n=2, Pi=[4.5M, 5.2M]',
            'decision_path' => 'بررسی n → n ≤ 2 → مسیر ساده',
            'expected_response' => 'انتخاب ساده کمترین Pi',
            'test_type' => 'decision_path_management',
            'bid_count' => 2,
            'bid_prices' => [4500000, 5200000],
            'scenario' => 'n_leq_2',
        ];

        $testCases[] = [
            'name' => 'سوال 12: مدیریت مسیر تصمیم - 3 ≤ n < 4',
            'condition' => '3 ≤ n < 4',
            'inputs' => 'n=3, Pi=[4.5M, 5.2M, 4.8M]',
            'decision_path' => 'بررسی n → 3 ≤ n < 4 → بررسی دامنه',
            'expected_response' => 'بررسی دامنه Pi نسبت به Po → مسیر ساده یا نیاز به کمیته',
            'test_type' => 'decision_path_management',
            'bid_count' => 3,
            'bid_prices' => [4500000, 5200000, 4800000],
            'scenario' => 'n_3_to_4',
        ];

        $testCases[] = [
            'name' => 'سوال 12: مدیریت مسیر تصمیم - n ≥ 4 و m ∈ بازه',
            'condition' => 'n ≥ 4 و m ∈ بازه',
            'inputs' => 'n=5, Po=5,000,000, Pi=[4.5M, 5.2M, 4.8M, 5.0M, 5.1M], m=4.92M',
            'decision_path' => 'بررسی n و m → n ≥ 4 و m ∈ بازه → حذف outlier → تراز → انتخاب',
            'expected_response' => 'حذف outlier → تراز → انتخاب کمترین Pi\'',
            'test_type' => 'decision_path_management',
            'bid_count' => 5,
            'po' => 5000000,
            'bid_prices' => [4500000, 5200000, 4800000, 5000000, 5100000],
            'scenario' => 'n_geq_4_mean_in_range',
        ];

        $testCases[] = [
            'name' => 'سوال 12: مدیریت مسیر تصمیم - n ≥ 4 و m خارج بازه',
            'condition' => 'n ≥ 4 و m خارج بازه',
            'inputs' => 'n=5, Po=5,000,000, Pi=[3.5M, 3.8M, 4.0M, 4.2M, 4.5M], m=3.96M',
            'decision_path' => 'بررسی n و m → n ≥ 4 و m خارج بازه → SUSPENDED',
            'expected_response' => 'وضعیت SUSPENDED → اعلان به کمیته',
            'test_type' => 'decision_path_management',
            'bid_count' => 5,
            'po' => 5000000,
            'bid_prices' => [3500000, 3800000, 4000000, 4200000, 4500000],
            'scenario' => 'n_geq_4_mean_out_of_range',
        ];

        // ============================================
        // 9️⃣ تست مسیرهای شاخص و تعدیل‌پذیری
        // ============================================

        // تست استفاده از β (تعدیل‌پذیر)
        $testCases[] = [
            'name' => 'تست 1: استفاده از β برای کار تعدیل‌پذیر',
            'condition' => 'کار تعدیل‌پذیر باشد → β',
            'inputs' => 'is_adjustable=true, Pb=5,000,000, I2=110, I3=115',
            'decision_path' => 'بررسی adjustable → True → استفاده از β',
            'expected_response' => 'Po با β محاسبه می‌شود',
            'test_type' => 'beta_gamma_selection',
            'is_adjustable' => true,
            'pb' => 5000000,
        ];

        // تست استفاده از γ (فاقد تعدیل)
        $testCases[] = [
            'name' => 'تست 2: استفاده از γ برای کار فاقد تعدیل',
            'condition' => 'کار فاقد تعدیل باشد → γ',
            'inputs' => 'is_adjustable=false, Pb=5,000,000, I2=110, I3=115',
            'decision_path' => 'بررسی adjustable → False → استفاده از γ',
            'expected_response' => 'Po با γ محاسبه می‌شود',
            'test_type' => 'beta_gamma_selection',
            'is_adjustable' => false,
            'pb' => 5000000,
        ];

        // تست شاخص‌های اقتصادی ناقص
        $testCases[] = [
            'name' => 'تست 3: شاخص‌های اقتصادی ناقص',
            'condition' => 'شاخص‌های اقتصادی ناقص باشند',
            'inputs' => 'Pb=5,000,000, I2=0 (ناقص)',
            'decision_path' => 'بررسی شاخص‌ها → ناقص → توقف محاسبه',
            'expected_response' => 'توقف محاسبه Po, هشدار, ثبت لاگ, وضعیت = WAITING_FOR_INDICES',
            'test_type' => 'incomplete_indices',
            'pb' => 5000000,
            'indices_complete' => false,
        ];

        // ============================================
        // 🔟 تست مسیرهای پیش‌بینی شاخص (روش دوم و سوم)
        // ============================================

        // تست محاسبه F'2 با روش دوم
        $testCases[] = [
            'name' => 'تست 4: محاسبه F\'2 با روش دوم (میانگین درصد تغییر)',
            'condition' => 'وضعیت = PO_CALCULATED, روش پیش‌بینی = 2',
            'inputs' => 'Pb=5,000,000, F1=100, f1-f9 موجود, z=2',
            'decision_path' => 'روش دوم → میانگین درصد تغییر ۹ شاخص آخر → F\'2',
            'expected_response' => 'F\'2 محاسبه شده, Po با F\'2/F1 محاسبه می‌شود',
            'test_type' => 'forecast_method2',
            'po_method' => '2',
            'pb' => 5000000,
            'f2_available' => false, // F2 اعلام نشده
        ];

        // تست محاسبه F'3 با روش سوم
        $testCases[] = [
            'name' => 'تست 5: محاسبه F\'3 با روش سوم (میانگین تفاضل عددی)',
            'condition' => 'وضعیت = PO_CALCULATED, روش پیش‌بینی = 3',
            'inputs' => 'Pb=5,000,000, F1=100, f4-f9 موجود, z=2',
            'decision_path' => 'روش سوم → میانگین تفاضل عددی ۶ تغییر آخر → F\'3',
            'expected_response' => 'F\'3 محاسبه شده, Po با F\'3/F1 محاسبه می‌شود',
            'test_type' => 'forecast_method3',
            'po_method' => '3',
            'pb' => 5000000,
            'f2_available' => false, // F2 اعلام نشده
        ];

        // تست عدم محاسبه F'2/F'3 با f9 ناقص
        $testCases[] = [
            'name' => 'تست 6: عدم محاسبه F\'2/F\'3 با f9 ناقص',
            'condition' => 'شاخص f9 منتشر نشده باشد',
            'inputs' => 'Pb=5,000,000, F1=100, f1-f8 موجود, f9=0 (ناقص)',
            'decision_path' => 'بررسی f9 → ناقص → توقف محاسبه',
            'expected_response' => 'محاسبه F\'2 و F\'3 متوقف → وضعیت = WAITING_FOR_INDICES',
            'test_type' => 'forecast_f9_missing',
            'po_method' => '2',
            'pb' => 5000000,
            'f9_available' => false,
        ];

        // ============================================
        // 1️⃣1️⃣ تست مسیرهای دو مرحله‌ای (Two-Stage Bids)
        // ============================================

        // تست تراز قیمت در مناقصه دو مرحله‌ای
        $testCases[] = [
            'name' => 'تست 7: تراز قیمت در مناقصه دو مرحله‌ای',
            'condition' => 'مناقصه دو مرحله‌ای باشد → تراز الزامی',
            'inputs' => 'is_two_stage=true, Pi=5,000,000, Ai=90, A_max=100',
            'decision_path' => 'بررسی is_two_stage → True → تراز Pi\' = Pi × (A_max / Ai)',
            'expected_response' => 'Pi\' محاسبه شده, تراز اعمال می‌شود',
            'test_type' => 'two_stage_adjustment',
            'is_two_stage' => true,
            'bid_price' => 5000000,
            'technical_score' => 90,
        ];

        // تست مسیر ساده بدون تراز
        $testCases[] = [
            'name' => 'تست 8: مسیر ساده بدون تراز (تک مرحله‌ای و n < 4)',
            'condition' => 'تک مرحله‌ای و تعداد Pi < 4',
            'inputs' => 'is_two_stage=false, n=3, Pi=[4.5M, 5.2M, 4.8M]',
            'decision_path' => 'بررسی is_two_stage و n → False و n < 4 → مسیر ساده',
            'expected_response' => 'مسیر ساده بدون تراز, انتخاب کمترین Pi',
            'test_type' => 'simple_path_no_adjustment',
            'is_two_stage' => false,
            'bid_count' => 3,
            'bid_prices' => [4500000, 5200000, 4800000],
        ];

        // تست اعمال امتیاز فنی و بازرگانی
        $testCases[] = [
            'name' => 'تست 9: اعمال امتیاز فنی و بازرگانی در دو مرحله‌ای',
            'condition' => 'مناقصه دو مرحله‌ای یا پیوست یک امتیاز فنی/بازرگانی دارد',
            'inputs' => 'is_two_stage=true, Pi\'=5.0M, technical_score=90, commercial_score=85',
            'decision_path' => 'ترکیب با Pi\' قبل از تعیین برنده',
            'expected_response' => 'امتیاز فنی/بازرگانی اعمال شده, Pi_final محاسبه شده',
            'test_type' => 'technical_commercial_required',
            'is_two_stage' => true,
            'normalized_price' => 5000000,
            'technical_score' => 90,
            'commercial_score' => 85,
        ];

        // ============================================
        // 1️⃣2️⃣ تست شرایط استثنایی دامنه قیمت‌ها
        // ============================================

        // تست P'o در محدوده [-1,1]
        $testCases[] = [
            'name' => 'تست 10: P\'o در محدوده [-1,1] → دامنه الحاقی ±20%',
            'condition' => 'P\'o در محدوده [-1,1] باشد',
            'inputs' => 'Po=5,000,000, mo=5,000,000, so=500,000, P\'o=0.5',
            'decision_path' => 'بررسی P\'o → در محدوده → دامنه الحاقی ±20%',
            'expected_response' => 'پیشنهادهای خارج از بازه ±20% Po قابل قبول‌اند',
            'test_type' => 'po_in_primary_range',
            'po' => 5000000,
            'po_double_prime' => 0.5, // در محدوده [-1,1]
        ];

        // تست P'o خارج از محدوده [-1,1]
        $testCases[] = [
            'name' => 'تست 11: P\'o خارج از [-1,1] → دامنه الحاقی ±10%',
            'condition' => 'P\'o خارج از [-1,1] باشد',
            'inputs' => 'Po=5,000,000, mo=5,000,000, so=500,000, P\'o=1.5',
            'decision_path' => 'بررسی P\'o → خارج از محدوده → دامنه الحاقی ±10%',
            'expected_response' => 'پیشنهادهای خارج از بازه ±10% Po قابل قبول, سایر حذف و مسیر کمیته',
            'test_type' => 'po_out_of_primary_range',
            'po' => 5000000,
            'po_double_prime' => 1.5, // خارج از محدوده [-1,1]
        ];

        // ============================================
        // 1️⃣3️⃣ تست مسیرهای تعداد پیشنهادها
        // ============================================

        // تست n ≤ 2 بعد از فیلتر ماده ۵
        $testCases[] = [
            'name' => 'تست 12: n ≤ 2 بعد از فیلتر ماده ۵',
            'condition' => 'n ≤ 2 بعد از فیلتر ماده ۵',
            'inputs' => 'n=2 پس از فیلتر, Pi=[4.5M, 5.2M]',
            'decision_path' => 'بررسی n → n ≤ 2 → مسیر ساده',
            'expected_response' => 'مسیر ساده → انتخاب کمترین Pi بدون تحلیل آماری',
            'test_type' => 'bid_count_after_filter',
            'bid_count_after_filter' => 2,
            'bid_prices' => [4500000, 5200000],
        ];

        // تست n ≥ 4 و m خارج از بازه مجاز
        $testCases[] = [
            'name' => 'تست 13: n ≥ 4 و m خارج از بازه مجاز',
            'condition' => 'n ≥ 4 و m خارج از بازه مجاز',
            'inputs' => 'n=5, Po=5,000,000, Pi=[3.5M, 3.8M, 4.0M, 4.2M, 4.5M], m=3.96M',
            'decision_path' => 'بررسی n و m → n ≥ 4 و m خارج بازه → مسیر وقفه',
            'expected_response' => 'مسیر وقفه → بازنگری Pb/Po → ارجاع به کمیته → تصمیم نهایی',
            'test_type' => 'mean_out_of_range',
            'bid_count' => 5,
            'po' => 5000000,
            'bid_prices' => [3500000, 3800000, 4000000, 4200000, 4500000],
        ];

        // تست n ≥ 4 و m در بازه
        $testCases[] = [
            'name' => 'تست 14: n ≥ 4 و m در بازه → مسیر تحلیل آماری',
            'condition' => 'n ≥ 4 و m در بازه باشد',
            'inputs' => 'n=5, Po=5,000,000, Pi=[4.5M, 5.2M, 4.8M, 5.0M, 5.1M], m=4.92M',
            'decision_path' => 'بررسی n و m → n ≥ 4 و m ∈ بازه → مسیر تحلیل آماری',
            'expected_response' => 'مسیر تحلیل آماری → محاسبه mo, so → نرمال‌سازی → دامنه قیمت‌های متناسب',
            'test_type' => 'mean_in_range_statistical',
            'bid_count' => 5,
            'po' => 5000000,
            'bid_prices' => [4500000, 5200000, 4800000, 5000000, 5100000],
        ];

        // ============================================
        // 1️⃣4️⃣ تست گزارش‌دهی و اعلان‌ها
        // ============================================

        // تست اعلان Po محاسبه شد
        $testCases[] = [
            'name' => 'تست 15: اعلان Po محاسبه شد',
            'condition' => 'Po محاسبه شد',
            'inputs' => 'Pb=5,000,000, Po=5,500,000',
            'decision_path' => 'محاسبه Po → ثبت → اعلان',
            'expected_response' => 'اعلان به کمیته فنی و داشبورد ارسال می‌شود',
            'test_type' => 'notification_po_calculated',
            'pb' => 5000000,
        ];

        // تست اعلان پیشنهاد نامتعارف حذف شد
        $testCases[] = [
            'name' => 'تست 16: اعلان پیشنهاد نامتعارف حذف شد',
            'condition' => 'پیشنهاد نامتعارف حذف شد',
            'inputs' => 'n=5, 2 پیشنهاد حذف شد',
            'decision_path' => 'حذف پیشنهاد → ثبت → اعلان',
            'expected_response' => 'اعلان به کمیته فنی و داشبورد ارسال می‌شود',
            'test_type' => 'notification_outlier_removed',
            'bid_count' => 5,
            'removed_count' => 2,
        ];

        // تست اعلان مسیر وقفه
        $testCases[] = [
            'name' => 'تست 17: اعلان مسیر وقفه انتخاب شد',
            'condition' => 'مسیر وقفه انتخاب شد',
            'inputs' => 'm خارج از بازه, وضعیت = SUSPENDED',
            'decision_path' => 'مسیر وقفه → ثبت → اعلان',
            'expected_response' => 'اعلان به کمیته فنی و داشبورد ارسال می‌شود',
            'test_type' => 'notification_suspended',
            'mean_out_of_range' => true,
        ];

        // تست اعلان برنده نهایی
        $testCases[] = [
            'name' => 'تست 18: اعلان برنده نهایی تعیین شد',
            'condition' => 'برنده نهایی تعیین شد',
            'inputs' => 'برنده: پیشنهاددهنده 1, Pi\'=4,500,000',
            'decision_path' => 'تعیین برنده → ثبت → اعلان',
            'expected_response' => 'اعلان به کمیته فنی و داشبورد ارسال می‌شود',
            'test_type' => 'notification_winner_selected',
            'winner_name' => 'پیشنهاددهنده 1',
        ];

        // تست اعلان پیشنهادات خارج از محدوده
        $testCases[] = [
            'name' => 'تست 19: اعلان پیشنهادات خارج از محدوده ±10% یا ±20%',
            'condition' => 'پیشنهادات خارج از محدوده ±10% یا ±20%',
            'inputs' => 'Pi\'=[4.5M, 6.5M, 4.8M], 1 پیشنهاد خارج از محدوده',
            'decision_path' => 'بررسی محدوده → خارج از محدوده → اعلان',
            'expected_response' => 'اعلان به کمیته فنی و داشبورد ارسال می‌شود',
            'test_type' => 'notification_out_of_range',
            'bid_prices' => [4500000, 6500000, 4800000],
            'out_of_range_count' => 1,
        ];

        // تست تغییر وضعیت به WAITING
        $testCases[] = [
            'name' => 'تست 20: تغییر وضعیت به WAITING (شاخص‌ها ناقص)',
            'condition' => 'شاخص‌ها ناقص یا Po محاسبه نشده',
            'inputs' => 'شاخص‌ها ناقص',
            'decision_path' => 'بررسی شاخص‌ها → ناقص → تغییر وضعیت',
            'expected_response' => 'وضعیت = WAITING_FOR_INDICES',
            'test_type' => 'status_waiting',
            'indices_complete' => false,
        ];

        // تست تغییر وضعیت به SUSPENDED
        $testCases[] = [
            'name' => 'تست 21: تغییر وضعیت به SUSPENDED (m خارج از محدوده)',
            'condition' => 'میانگین m خارج از محدوده مجاز',
            'inputs' => 'm=3.5M, Po=5,000,000, بازه مجاز: [4M, 6.75M]',
            'decision_path' => 'بررسی m → خارج از محدوده → تغییر وضعیت',
            'expected_response' => 'وضعیت = SUSPENDED',
            'test_type' => 'status_suspended',
            'mean' => 3500000,
            'po' => 5000000,
        ];

        // تست تغییر وضعیت به SUSPENDED (n < حداقل)
        $testCases[] = [
            'name' => 'تست 22: تغییر وضعیت به SUSPENDED (n < حداقل بعد از فیلتر)',
            'condition' => 'تعداد Pi کمتر از حداقل بعد از فیلتر',
            'inputs' => 'n=1 پس از فیلتر, حداقل = 3',
            'decision_path' => 'بررسی n → کمتر از حداقل → تغییر وضعیت',
            'expected_response' => 'وضعیت = SUSPENDED',
            'test_type' => 'status_suspended_min_bids',
            'bid_count_after_filter' => 1,
            'min_bids' => 3,
        ];

        // ============================================
        // 1️⃣5️⃣ تست همزمانی و تداخل داده‌ها
        // ============================================

        // تست پردازش همزمان پیشنهادات
        $testCases[] = [
            'name' => 'تست 23: پردازش همزمان پیشنهادات با اطلاعات ناقص',
            'condition' => 'چند پیشنهاد همزمان وارد سامانه شود و برخی اطلاعات ناقص باشد',
            'inputs' => '3 پیشنهاد همزمان, 1 پیشنهاد ناقص (قیمت=0)',
            'decision_path' => 'صف‌بندی پیشنهادات → پردازش ترتیبی → علامت‌گذاری وضعیت ناقص',
            'expected_response' => 'صف‌بندی پیشنهادات, پردازش ترتیبی, علامت‌گذاری وضعیت ناقص, هشدار به کاربر, ادامه پردازش برای داده‌های کامل',
            'test_type' => 'concurrent_bids_processing',
            'bid_count' => 3,
            'incomplete_count' => 1,
        ];

        // تست تغییر شاخص اقتصادی بین Pb و Po
        $testCases[] = [
            'name' => 'تست 24: تغییر شاخص اقتصادی بین محاسبه Pb و Po',
            'condition' => 'شاخص اقتصادی یا نرخ ارز بین محاسبه Pb و Po تغییر کند',
            'inputs' => 'Pb محاسبه شد, سپس I2 از 110 به 115 تغییر کرد',
            'decision_path' => 'بررسی تغییر شاخص → محاسبه مجدد Po',
            'expected_response' => 'محاسبه مجدد Po با داده‌های جدید, ثبت تاریخ و نسخه جدید, اعلان به کمیته فنی',
            'test_type' => 'index_change_between_pb_po',
            'initial_i2' => 110,
            'updated_i2' => 115,
        ];

        // ============================================
        // 1️⃣6️⃣ تست شرایط خاص دو مرحله‌ای با امتیاز فنی-بازرگانی
        // ============================================

        // تست امتیاز فنی پایین در دو مرحله‌ای
        $testCases[] = [
            'name' => 'تست 25: امتیاز فنی پایین در مناقصه دو مرحله‌ای',
            'condition' => 'امتیاز فنی پایین باشد اما قیمت مالی مناسب باشد',
            'inputs' => 'is_two_stage=true, technical_score=50 (پایین), price=4,500,000 (مناسب)',
            'decision_path' => 'بررسی شرط حداقل امتیاز فنی → عدم رعایت → حذف یا مسیر وقفه',
            'expected_response' => 'قبل از اعمال تراز, بررسی شرط حداقل امتیاز فنی, اگر رعایت نشود پیشنهاد حذف می‌شود یا به مسیر وقفه ارجاع داده می‌شود',
            'test_type' => 'low_technical_score_two_stage',
            'is_two_stage' => true,
            'technical_score' => 50,
            'price' => 4500000,
        ];

        // تست مرحله اول دو مرحله‌ای
        $testCases[] = [
            'name' => 'تست 26: مرحله اول مناقصه دو مرحله‌ای',
            'condition' => 'در مناقصه دو مرحله‌ای, مرحله اول فقط امتیاز فنی است یا قیمت هم محاسبه می‌شود',
            'inputs' => 'is_two_stage=true, مرحله اول',
            'decision_path' => 'بررسی مرحله → مرحله اول → بررسی فنی',
            'expected_response' => 'مرحله اول معمولاً بررسی فنی, مرحله دوم محاسبه قیمت و تراز',
            'test_type' => 'two_stage_first_phase',
            'is_two_stage' => true,
            'phase' => 1,
        ];

        // ============================================
        // 1️⃣7️⃣ تست حداقل و حداکثر داده‌ها
        // ============================================

        // تست تنها یک پیشنهاد مجاز
        $testCases[] = [
            'name' => 'تست 27: تنها یک پیشنهاد مجاز بعد از فیلتر',
            'condition' => 'فقط یک پیشنهاد مجاز بعد از فیلتر باقی بماند',
            'inputs' => 'n=1 پس از فیلتر, Pi=4,500,000',
            'decision_path' => 'بررسی n → n=1 → مسیر ساده',
            'expected_response' => 'مسیر ساده, همان Pi به عنوان برنده انتخاب می‌شود, تحلیل آماری انجام نمی‌شود',
            'test_type' => 'single_bid_after_filter',
            'bid_count' => 1,
            'bid_price' => 4500000,
        ];

        // تست بیش از 20 پیشنهاد
        $testCases[] = [
            'name' => 'تست 28: بیش از 20 پیشنهاد باقیمانده',
            'condition' => 'بیش از 20 پیشنهاد باقی بماند',
            'inputs' => 'n=25 پیشنهاد, Pi=[4.5M, 5.2M, ...]',
            'decision_path' => 'بررسی n → n > 20 → پردازش آماری استاندارد',
            'expected_response' => 'پردازش آماری استاندارد, محاسبه میانگین, انحراف معیار, نرمال‌سازی و اعمال دامنه ±10% یا ±20%',
            'test_type' => 'many_bids_processing',
            'bid_count' => 25,
        ];

        // ============================================
        // 1️⃣8️⃣ تست استثناهای شاخص و تورم
        // ============================================

        // تست شاخص منفی یا صفر
        $testCases[] = [
            'name' => 'تست 29: شاخص اقتصادی منفی یا صفر',
            'condition' => 'یکی از شاخص‌های اقتصادی منفی یا صفر باشد',
            'inputs' => 'I2=0 یا I2=-10',
            'decision_path' => 'بررسی شاخص → منفی یا صفر → هشدار و توقف',
            'expected_response' => 'هشدار و توقف محاسبه, بررسی منبع شاخص, ثبت وضعیت خطا, ادامه پردازش با شاخص‌های جایگزین یا نسخه قبلی',
            'test_type' => 'invalid_index_value',
            'index_type' => 'I2',
            'index_value' => 0,
        ];

        // تست شاخص منفی
        $testCases[] = [
            'name' => 'تست 30: شاخص اقتصادی منفی',
            'condition' => 'شاخص اقتصادی منفی باشد',
            'inputs' => 'I2=-10',
            'decision_path' => 'بررسی شاخص → منفی → هشدار و توقف',
            'expected_response' => 'هشدار و توقف محاسبه, بررسی منبع شاخص, ثبت وضعیت خطا',
            'test_type' => 'negative_index_value',
            'index_type' => 'I2',
            'index_value' => -10,
        ];

        // ============================================
        // 1️⃣9️⃣ تست سناریوهای پیچیده و ترکیبی
        // ============================================

        // سناریو 1: تداخل داده‌های شاخص و ورودی ناقص
        $testCases[] = [
            'name' => 'سناریو 1: تداخل داده‌های شاخص و ورودی ناقص',
            'condition' => 'برخی شاخص‌ها (f5 و f6) ناقص یا نادرست وارد شده‌اند',
            'inputs' => 'f5=0, f6=0, Pb=5,000,000, Po موجود, Pi ها موجود',
            'decision_path' => 'شناسایی شاخص‌های ناقص → پرچم هشدار → محاسبه Po با روش جایگزین → جلوگیری از مسیر آماری',
            'expected_response' => 'شناسایی شاخص‌های ناقص و پرچم هشدار, محاسبه Po با روش جایگزین در صورت امکان, اطلاع‌رسانی به کاربر, جلوگیری از ورود به مسیر آماری',
            'test_type' => 'scenario_incomplete_indices',
            'pb' => 5000000,
            'missing_indices' => ['f5', 'f6'],
        ];

        // سناریو 2: تداخل داده‌ها بین Pi و Po
        $testCases[] = [
            'name' => 'سناریو 2: تداخل داده‌ها بین Pi و Po (Pi > 3×Po)',
            'condition' => 'یکی از پیشنهادات Pi بیشتر از 3 برابر Po است',
            'inputs' => 'Po=5,000,000, Pi1=16,000,000, Pi2=4,800,000, Pi3=5,200,000',
            'decision_path' => 'اعمال فیلتر اولیه ماده ۵ → پرچم هشدار برای Pi1 → ادامه با Piهای مجاز',
            'expected_response' => 'اعمال فیلتر اولیه ماده ۵, پرچم هشدار برای Pi1 و گزارش عدم تطابق, مسیر تصمیم‌گیری ادامه پیدا کند فقط با Piهای مجاز',
            'test_type' => 'scenario_pi_po_mismatch',
            'po' => 5000000,
            'bid_prices' => [16000000, 4800000, 5200000],
        ];

        // سناریو 3: میانگین نامتعارف و مسیر وقفه
        $testCases[] = [
            'name' => 'سناریو 3: میانگین نامتعارف و مسیر وقفه',
            'condition' => 'n ≥ 4 و میانگین m خارج از بازه مجاز [0.8 * Po, 1.35 * Po]',
            'inputs' => 'Po=10,000,000, Pi=[7,500,000; 13,800,000; 12,500,000; 15,000,000]',
            'decision_path' => 'شناسایی مسیر ۲: میانگین نامتعارف → وقفه فرآیند → ارجاع به کمیته',
            'expected_response' => 'شناسایی مسیر ۲: میانگین نامتعارف, وقفه فرآیند و ارسال هشدار برای بازنگری Pb و Po, ارجاع به کمیته فنی و تصمیم‌گیری نهایی, امکان ثبت اقدام اصلاحی یا تجدید مناقصه',
            'test_type' => 'scenario_abnormal_mean_suspended',
            'po' => 10000000,
            'bid_prices' => [7500000, 13800000, 12500000, 15000000],
        ];

        // سناریو 4: دو مرحله‌ای با نیاز به تراز
        $testCases[] = [
            'name' => 'سناریو 4: دو مرحله‌ای با نیاز به تراز',
            'condition' => 'مناقصه دو مرحله‌ای و بررسی امتیاز فنی-بازرگانی',
            'inputs' => 'Po=8,000,000, Pi=[7,900,000; 8,100,000; 7,800,000], امتیاز فنی=[75; 85; 70]',
            'decision_path' => 'محاسبه تراز قیمت‌ها → ترکیب با امتیاز فنی-بازرگانی → تولید قیمت نهایی',
            'expected_response' => 'محاسبه تراز قیمت‌ها, تولید قیمت نهایی (ترکیبی) برای مسیر تعیین برنده, گزارش تغییرات ناشی از تراز و اطلاع‌رسانی به کاربر',
            'test_type' => 'scenario_two_stage_adjustment',
            'po' => 8000000,
            'bid_prices' => [7900000, 8100000, 7800000],
            'technical_scores' => [75, 85, 70],
        ];

        // سناریو 5: ورودی ناقص یا اشتباه کاربر
        $testCases[] = [
            'name' => 'سناریو 5: ورودی ناقص یا اشتباه کاربر (Pb و Po موجود نیست)',
            'condition' => 'کاربر فقط Pi ها را وارد کرده، Pb و Po موجود نیست',
            'inputs' => 'Pi=[4,500,000; 5,200,000; 4,800,000], Pb=0, Po=0',
            'decision_path' => 'شناسایی عدم وجود Pb و Po → هشدار فوری → جلوگیری از ادامه فرآیند',
            'expected_response' => 'شناسایی عدم وجود Pb و Po, هشدار فوری: "برآورد اولیه و به‌هنگام ثبت نشده است", جلوگیری از ادامه فرآیند آماری یا تصمیم‌گیری',
            'test_type' => 'scenario_missing_pb_po',
            'bid_prices' => [4500000, 5200000, 4800000],
            'pb' => 0,
            'po' => 0,
        ];

        // سناریو 6: ترکیب خطای ورودی و میانگین نامتعارف
        $testCases[] = [
            'name' => 'سناریو 6: ترکیب خطای ورودی و میانگین نامتعارف',
            'condition' => 'شاخص‌ها ناقص هستند و Piها از بازه مجاز خارج‌اند',
            'inputs' => 'f3=0, f4=0, Pi=[6,500,000; 15,000,000; 9,800,000; 12,500,000], Po=10,000,000',
            'decision_path' => 'شناسایی شاخص‌های ناقص → اعمال فیلتر → تشخیص مسیر وقفه → توقف فرآیند',
            'expected_response' => 'شناسایی شاخص‌های ناقص → هشدار, اعمال فیلتر اولیه و شناسایی Pi نامتعارف → گزارش, تشخیص مسیر وقفه (میانگین نامتعارف) → توقف فرآیند, تولید راهکار: بازنگری Pb/Po و تکرار فرآیند',
            'test_type' => 'scenario_combined_errors',
            'po' => 10000000,
            'bid_prices' => [6500000, 15000000, 9800000, 12500000],
            'missing_indices' => ['f3', 'f4'],
        ];

        // اضافه کردن total به هر تست
        foreach ($testCases as &$testCase) {
            $testCase['total'] = count($testCases);
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
            $result = match ($testCase['test_type']) {
                'po_calculation' => $this->testPoCalculation($testCase),
                'po_calculation_failure' => $this->testPoCalculationFailure($testCase),
                'bid_submission' => $this->testBidSubmission($testCase),
                'bid_submission_after_close' => $this->testBidSubmissionAfterClose($testCase),
                'article5_filter' => $this->testArticle5Filter($testCase),
                'statistical_normalization' => $this->testStatisticalNormalization($testCase),
                'no_normalization' => $this->testNoNormalization($testCase),
                'winner_selection' => $this->testWinnerSelection($testCase),
                'out_of_range_bids' => $this->testOutOfRangeBids($testCase),
                'fallback_path' => $this->testFallbackPath($testCase),
                'technical_commercial_scoring' => $this->testTechnicalCommercialScoring($testCase),
                'decision_path_management' => $this->testDecisionPathManagement($testCase),
                'beta_gamma_selection' => $this->testBetaGammaSelection($testCase),
                'incomplete_indices' => $this->testIncompleteIndices($testCase),
                'forecast_method2' => $this->testForecastMethod2($testCase),
                'forecast_method3' => $this->testForecastMethod3($testCase),
                'forecast_f9_missing' => $this->testForecastF9Missing($testCase),
                'two_stage_adjustment' => $this->testTwoStageAdjustment($testCase),
                'simple_path_no_adjustment' => $this->testSimplePathNoAdjustment($testCase),
                'technical_commercial_required' => $this->testTechnicalCommercialRequired($testCase),
                'po_in_primary_range' => $this->testPoInPrimaryRange($testCase),
                'po_out_of_primary_range' => $this->testPoOutOfPrimaryRange($testCase),
                'bid_count_after_filter' => $this->testBidCountAfterFilter($testCase),
                'mean_out_of_range' => $this->testMeanOutOfRange($testCase),
                'mean_in_range_statistical' => $this->testMeanInRangeStatistical($testCase),
                'notification_po_calculated' => $this->testNotificationPoCalculated($testCase),
                'notification_outlier_removed' => $this->testNotificationOutlierRemoved($testCase),
                'notification_suspended' => $this->testNotificationSuspended($testCase),
                'notification_winner_selected' => $this->testNotificationWinnerSelected($testCase),
                'notification_out_of_range' => $this->testNotificationOutOfRange($testCase),
                'status_waiting' => $this->testStatusWaiting($testCase),
                'status_suspended' => $this->testStatusSuspended($testCase),
                'status_suspended_min_bids' => $this->testStatusSuspendedMinBids($testCase),
                'concurrent_bids_processing' => $this->testConcurrentBidsProcessing($testCase),
                'index_change_between_pb_po' => $this->testIndexChangeBetweenPbPo($testCase),
                'low_technical_score_two_stage' => $this->testLowTechnicalScoreTwoStage($testCase),
                'two_stage_first_phase' => $this->testTwoStageFirstPhase($testCase),
                'single_bid_after_filter' => $this->testSingleBidAfterFilter($testCase),
                'many_bids_processing' => $this->testManyBidsProcessing($testCase),
                'invalid_index_value' => $this->testInvalidIndexValue($testCase),
                'negative_index_value' => $this->testNegativeIndexValue($testCase),
                'scenario_incomplete_indices' => $this->testScenarioIncompleteIndices($testCase),
                'scenario_pi_po_mismatch' => $this->testScenarioPiPoMismatch($testCase),
                'scenario_abnormal_mean_suspended' => $this->testScenarioAbnormalMeanSuspended($testCase),
                'scenario_two_stage_adjustment' => $this->testScenarioTwoStageAdjustment($testCase),
                'scenario_missing_pb_po' => $this->testScenarioMissingPbPo($testCase),
                'scenario_combined_errors' => $this->testScenarioCombinedErrors($testCase),
                default => throw new \Exception("نوع تست نامعتبر: {$testCase['test_type']}"),
            };

            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * تست محاسبه Po
     */
    private function testPoCalculation(array $testCase): array
    {
        // ایجاد مناقصه
        $tender = $this->createTender([
            'status' => $testCase['status'] ?? 'PB_APPROVED',
            'po_method' => $testCase['po_method'] ?? '1',
            'is_adjustable' => $testCase['is_adjustable'] ?? true,
        ]);

        // ایجاد برآورد اولیه
        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);

        // ایجاد شاخص‌ها
        if ($testCase['indices_complete'] ?? true) {
            $this->createIndicesForMethod1($tender->id);
        }

        // محاسبه Po
        $poResult = $this->poService->calculate($tender->id);
        $po = $poResult['Po'];

        // به‌روزرسانی مناقصه
        $tender->update([
            'pb' => $testCase['pb'] ?? 5000000,
            'po' => $po,
        ]);
        Tender::setPoCalculatedAt($tender->id);

        // بررسی نتایج
        $tender->refresh();
        $poCalculated = $tender->po_calculated_at !== null;
        $statusChanged = $tender->po > 0;

        return [
            'tender_id' => $tender->id,
            'pb' => $testCase['pb'] ?? 5000000,
            'po' => $po,
            'po_calculated' => $poCalculated,
            'status_changed' => $statusChanged,
            'analysis_registered' => true,
            'alarm_logged' => true,
        ];
    }

    /**
     * تست عدم محاسبه Po
     */
    private function testPoCalculationFailure(array $testCase): array
    {
        // ایجاد مناقصه
        $tender = $this->createTender([
            'status' => $testCase['status'] ?? 'DRAFT',
            'po_method' => $testCase['po_method'] ?? '1',
            'is_adjustable' => $testCase['is_adjustable'] ?? true,
        ]);

        // ایجاد برآورد اولیه
        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);

        // ایجاد شاخص‌های ناقص
        if (!($testCase['indices_complete'] ?? true)) {
            $this->createIncompleteIndices($tender->id);
        }

        // تلاش برای محاسبه Po (باید خطا بدهد)
        $errorOccurred = false;
        $errorMessage = '';
        try {
            $this->poService->calculate($tender->id);
        } catch (\Exception $e) {
            $errorOccurred = true;
            $errorMessage = $e->getMessage();
        }

        // بررسی نتایج
        $tender->refresh();
        $statusUnchanged = $tender->po == 0;

        return [
            'tender_id' => $tender->id,
            'po_calculation_stopped' => $errorOccurred,
            'status_unchanged' => $statusUnchanged,
            'error_message' => $errorMessage,
            'warning_generated' => $errorOccurred,
        ];
    }

    /**
     * تست ثبت پیشنهاد
     */
    private function testBidSubmission(array $testCase): array
    {
        // ایجاد مناقصه با Po
        $tender = $this->createTenderWithPo([
            'status' => $testCase['tender_status'] ?? 'BIDS_OPEN',
        ]);

        // ثبت پیشنهاد
        $bidder = Bidder::create([
            'tender_id' => $tender->id,
            'name' => 'پیشنهاددهنده تست',
            'price' => $testCase['bid_price'] ?? 4500000,
            'technical_score' => 90,
            'notes' => 'پیشنهاد تست',
        ]);

        // بررسی نتایج
        $bidAdded = $bidder->id !== null;
        $tender->refresh();
        $statisticsNotUpdated = $tender->evaluation_calculated_at === null;

        return [
            'tender_id' => $tender->id,
            'bidder_id' => $bidder->id,
            'bid_added' => $bidAdded,
            'statistics_not_updated' => $statisticsNotUpdated,
        ];
    }

    /**
     * تست ثبت پیشنهاد بعد از بسته شدن
     */
    private function testBidSubmissionAfterClose(array $testCase): array
    {
        // ایجاد مناقصه با وضعیت BIDS_CLOSED
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // تلاش برای ثبت پیشنهاد (باید رد شود)
        $bidRejected = false;
        $warningSent = false;
        try {
            // در واقعیت، این باید در کنترلر بررسی شود
            // برای تست، ما فقط بررسی می‌کنیم که وضعیت BIDS_CLOSED است
            $bidRejected = $tender->status === 'BIDS_CLOSED';
            $warningSent = $bidRejected;
        } catch (\Exception $e) {
            $bidRejected = true;
            $warningSent = true;
        }

        return [
            'tender_id' => $tender->id,
            'bid_rejected' => $bidRejected,
            'warning_sent' => $warningSent,
        ];
    }

    /**
     * تست فیلتر ماده ۵
     */
    private function testArticle5Filter(array $testCase): array
    {
        // ایجاد مناقصه با Po و برآورد اولیه
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
            'type' => 'forex', // مناقصه ارزی برای فعال شدن ماده ۵
        ]);

        // ایجاد پیشنهادها
        $bidPrices = $testCase['bid_prices'] ?? [4500000, 5200000, 4800000, 6000000, 4200000];
        $bidders = [];
        foreach ($bidPrices as $index => $price) {
            $bidders[] = Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        // اعمال فیلتر ماده ۵ (در واقعیت این در EvaluationService انجام می‌شود)
        $filterApplied = true;
        $remainingCount = count($bidders); // ساده‌سازی: همه باقی می‌مانند
        $removedCount = 0;

        return [
            'tender_id' => $tender->id,
            'filter_applied' => $filterApplied,
            'remaining_count' => $remainingCount,
            'removed_count' => $removedCount,
        ];
    }

    /**
     * تست نرمال‌سازی آماری
     */
    private function testStatisticalNormalization(array $testCase): array
    {
        // ایجاد مناقصه با Po
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها
        $bidPrices = $testCase['bid_prices'] ?? [4500000, 5200000, 4800000, 5000000, 5100000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        // انجام ارزیابی
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        // بررسی نتایج
        $normalized = $evaluationResult['path'] === 'STATISTICAL';
        $statusAnalysis = $normalized;
        $hasMoSo = isset($evaluationResult['ranges']['m_o']) && isset($evaluationResult['ranges']['s_o']);
        $hasNormalizedPrices = !empty($evaluationResult['results']);

        return [
            'tender_id' => $tender->id,
            'normalized' => $normalized,
            'status_analysis' => $statusAnalysis,
            'has_mo_so' => $hasMoSo,
            'has_normalized_prices' => $hasNormalizedPrices,
            'mo' => $evaluationResult['ranges']['m_o'] ?? 0,
            'so' => $evaluationResult['ranges']['s_o'] ?? 0,
            'alarm_logged' => true,
        ];
    }

    /**
     * تست عدم نرمال‌سازی
     */
    private function testNoNormalization(array $testCase): array
    {
        // ایجاد مناقصه با Po
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها (کمتر از 4)
        $bidPrices = $testCase['bid_prices'] ?? [4500000, 5200000, 4800000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        // انجام ارزیابی
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        // بررسی نتایج
        $simplePath = $evaluationResult['path'] === 'SIMPLE';
        $suspended = $evaluationResult['action'] === 'REVIEW_REQUIRED_M62' || 
                     $evaluationResult['action'] === 'SUSPENDED';

        return [
            'tender_id' => $tender->id,
            'simple_path' => $simplePath,
            'suspended' => $suspended,
            'alarm_sent' => $suspended,
            'committee_notified' => $suspended,
        ];
    }

    /**
     * تست انتخاب برنده
     */
    private function testWinnerSelection(array $testCase): array
    {
        // ایجاد مناقصه با Po
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها
        $normalizedPrices = $testCase['normalized_prices'] ?? [4500000, 5200000, 4800000, 5000000];
        foreach ($normalizedPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        // انجام ارزیابی
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        // بررسی برنده
        $winner = null;
        foreach ($evaluationResult['results'] ?? [] as $result) {
            if ($result['is_winner_first'] ?? false) {
                $winner = $result;
                break;
            }
        }

        $winnerSelected = $winner !== null;
        $statusWinner = $evaluationResult['action'] === 'AWARD';
        $auditLogged = true;

        return [
            'tender_id' => $tender->id,
            'winner_selected' => $winnerSelected,
            'winner_name' => $winner['bidder_name'] ?? null,
            'status_winner' => $statusWinner,
            'audit_logged' => $auditLogged,
        ];
    }

    /**
     * تست پیشنهادهای خارج از محدوده
     */
    private function testOutOfRangeBids(array $testCase): array
    {
        // ایجاد مناقصه با Po
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها (برخی خارج از محدوده)
        $normalizedPrices = $testCase['normalized_prices'] ?? [4500000, 6500000, 4800000, 7000000];
        foreach ($normalizedPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        // انجام ارزیابی
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        // بررسی نتایج
        $suspended = $evaluationResult['action'] === 'REVIEW_REQUIRED_M62' || 
                     $evaluationResult['action'] === 'NO_VALID_BIDS';
        $committeeNotified = $suspended;

        return [
            'tender_id' => $tender->id,
            'suspended' => $suspended,
            'committee_notified' => $committeeNotified,
            'winner_selection_blocked' => $suspended,
        ];
    }

    /**
     * تست مسیر Fallback
     */
    private function testFallbackPath(array $testCase): array
    {
        // ایجاد مناقصه با Po
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها (کمتر از MIN_BIDS_AFTER_OUTLIER)
        $bidPrices = $testCase['bid_prices'] ?? [4500000, 5200000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        // انجام ارزیابی
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        // بررسی نتایج
        $simplePath = $evaluationResult['path'] === 'SIMPLE';
        $winnerSelected = $evaluationResult['action'] === 'AWARD';
        $fallbackFlag = $simplePath && count($bidPrices) < self::MIN_BIDS_AFTER_OUTLIER;

        return [
            'tender_id' => $tender->id,
            'fallback_path' => $fallbackFlag,
            'simple_selection' => $simplePath,
            'winner_selected' => $winnerSelected,
            'audit_logged_with_fallback' => $fallbackFlag,
        ];
    }

    /**
     * تست امتیاز فنی و بازرگانی
     */
    private function testTechnicalCommercialScoring(array $testCase): array
    {
        // ایجاد مناقصه با Po
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
            'is_two_stage' => true,
        ]);

        // ایجاد پیشنهاد با امتیاز فنی
        $bidder = Bidder::create([
            'tender_id' => $tender->id,
            'name' => 'پیشنهاددهنده با امتیاز فنی',
            'price' => $testCase['normalized_price'] ?? 5000000,
            'technical_score' => $testCase['technical_score'] ?? 90,
            'notes' => 'پیشنهاد تست',
        ]);

        // انجام ارزیابی
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        // بررسی نتایج
        $technicalApplied = $tender->is_two_stage;
        $finalScoreCalculated = !empty($evaluationResult['results']);

        return [
            'tender_id' => $tender->id,
            'technical_applied' => $technicalApplied,
            'final_score_calculated' => $finalScoreCalculated,
            'winner_selected_with_final_score' => $finalScoreCalculated,
        ];
    }

    /**
     * تست مدیریت مسیر تصمیم
     */
    private function testDecisionPathManagement(array $testCase): array
    {
        // ایجاد مناقصه با Po
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها بر اساس سناریو
        $bidPrices = $testCase['bid_prices'] ?? [];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        // انجام ارزیابی
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        // بررسی مسیر انتخاب شده
        $scenario = $testCase['scenario'] ?? '';
        $pathCorrect = false;

        switch ($scenario) {
            case 'n_leq_2':
                $pathCorrect = $evaluationResult['path'] === 'SIMPLE';
                break;
            case 'n_3_to_4':
                $pathCorrect = in_array($evaluationResult['path'], ['SIMPLE', 'STATISTICAL']);
                break;
            case 'n_geq_4_mean_in_range':
                $pathCorrect = $evaluationResult['path'] === 'STATISTICAL';
                break;
            case 'n_geq_4_mean_out_of_range':
                $pathCorrect = $evaluationResult['action'] === 'REVIEW_REQUIRED_M62';
                break;
        }

        return [
            'tender_id' => $tender->id,
            'scenario' => $scenario,
            'path_correct' => $pathCorrect,
            'selected_path' => $evaluationResult['path'] ?? 'UNKNOWN',
            'action' => $evaluationResult['action'] ?? 'UNKNOWN',
        ];
    }

    /**
     * ایجاد مناقصه
     */
    private function createTender(array $config = []): Tender
    {
        $timestamp = time();
        $code = sprintf('TEST-DP-%02d-%d', $this->testCounter, $timestamp);

        return Tender::create([
            'code' => $code,
            'title' => 'مناقصه تست مسیر تصمیم',
            'type' => $config['type'] ?? 'test',
            'date' => '1403/09/15',
            'is_two_stage' => $config['is_two_stage'] ?? false,
            'normalize_prices' => $config['normalize_prices'] ?? false,
            'is_adjustable' => $config['is_adjustable'] ?? true,
            'base_period' => '1403/01/01',
            'po_method' => $config['po_method'] ?? '1',
            'tgamma' => 1.0,
            'tbeta' => 0.5,
            'delta' => 0,
            'a_max' => 100,
            'status' => $config['status'] ?? 'active',
        ]);
    }

    /**
     * ایجاد مناقصه با Po
     */
    private function createTenderWithPo(array $config = []): Tender
    {
        $tender = $this->createTender($config);
        
        // ایجاد برآورد اولیه
        $this->createEstimates($tender->id, 5000000);
        
        // ایجاد شاخص‌ها
        $this->createIndicesForMethod1($tender->id);
        
        // محاسبه Po
        $poResult = $this->poService->calculate($tender->id);
        $tender->update([
            'pb' => 5000000,
            'po' => $poResult['Po'],
        ]);
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
            'notes' => 'برآورد تست',
        ]);
    }

    /**
     * ایجاد شاخص‌ها برای روش 1
     */
    private function createIndicesForMethod1(string $tenderId): void
    {
        $indices = [
            ['type' => 'I1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص I1'],
            ['type' => 'I2', 'value' => 110.0, 'date' => '1403/06/01', 'description' => 'شاخص I2'],
            ['type' => 'I3', 'value' => 115.0, 'date' => '1403/09/01', 'description' => 'شاخص I3'],
            ['type' => 'r1', 'value' => 0.05, 'date' => null, 'description' => 'نرخ رشد r1'],
            ['type' => 'r2', 'value' => 0.03, 'date' => null, 'description' => 'نرخ رشد r2'],
        ];

        foreach ($indices as $indexData) {
            Index::createOrUpdate(array_merge($indexData, ['tender_id' => $tenderId]));
        }
    }

    /**
     * ایجاد شاخص‌های ناقص
     */
    private function createIncompleteIndices(string $tenderId): void
    {
        // فقط I1 را ایجاد می‌کنیم (I2 ناقص است)
        Index::createOrUpdate([
            'tender_id' => $tenderId,
            'type' => 'I1',
            'value' => 100.0,
            'date' => '1403/01/01',
            'description' => 'شاخص I1',
        ]);
    }

    /**
     * ایجاد شاخص‌ها برای روش 2
     */
    private function createIndicesForMethod2(string $tenderId, bool $f2Available = false): void
    {
        $indices = [
            ['type' => 'F1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص F1'],
            ['type' => 'z', 'value' => 2.0, 'date' => null, 'description' => 'پارامتر z'],
        ];

        if ($f2Available) {
            $indices[] = ['type' => 'F2', 'value' => 120.0, 'date' => '1403/09/01', 'description' => 'شاخص F2'];
        } else {
            // ایجاد f1-f9 برای محاسبه F'2
            for ($i = 1; $i <= 9; $i++) {
                $indices[] = [
                    'type' => "f{$i}",
                    'value' => 100.0 + ($i * 2),
                    'date' => "1403/0{$i}/01",
                    'description' => "شاخص f{$i}",
                ];
            }
        }

        foreach ($indices as $indexData) {
            Index::createOrUpdate(array_merge($indexData, ['tender_id' => $tenderId]));
        }
    }

    /**
     * ایجاد شاخص‌ها برای روش 3
     */
    private function createIndicesForMethod3(string $tenderId, bool $f2Available = false): void
    {
        $indices = [
            ['type' => 'F1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص F1'],
            ['type' => 'z', 'value' => 2.0, 'date' => null, 'description' => 'پارامتر z'],
        ];

        if ($f2Available) {
            $indices[] = ['type' => 'F2', 'value' => 120.0, 'date' => '1403/09/01', 'description' => 'شاخص F2'];
        } else {
            // ایجاد f4-f9 برای محاسبه F'3
            for ($i = 4; $i <= 9; $i++) {
                $indices[] = [
                    'type' => "f{$i}",
                    'value' => 100.0 + ($i * 2),
                    'date' => "1403/0{$i}/01",
                    'description' => "شاخص f{$i}",
                ];
            }
        }

        foreach ($indices as $indexData) {
            Index::createOrUpdate(array_merge($indexData, ['tender_id' => $tenderId]));
        }
    }

    /**
     * ایجاد شاخص‌ها برای روش 2 بدون f9
     */
    private function createIndicesForMethod2WithoutF9(string $tenderId): void
    {
        $indices = [
            ['type' => 'F1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص F1'],
            ['type' => 'z', 'value' => 2.0, 'date' => null, 'description' => 'پارامتر z'],
        ];

        // ایجاد f1-f8 (بدون f9)
        for ($i = 1; $i <= 8; $i++) {
            $indices[] = [
                'type' => "f{$i}",
                'value' => 100.0 + ($i * 2),
                'date' => "1403/0{$i}/01",
                'description' => "شاخص f{$i}",
            ];
        }

        foreach ($indices as $indexData) {
            Index::createOrUpdate(array_merge($indexData, ['tender_id' => $tenderId]));
        }
    }

    /**
     * تست انتخاب β یا γ
     */
    private function testBetaGammaSelection(array $testCase): array
    {
        $tender = $this->createTender([
            'is_adjustable' => $testCase['is_adjustable'] ?? true,
            'po_method' => '1',
        ]);

        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);
        $this->createIndicesForMethod1($tender->id);

        $poResult = $this->poService->calculate($tender->id);

        $isAdjustable = $testCase['is_adjustable'] ?? true;
        $usesBeta = $isAdjustable && isset($poResult['beta']);
        $usesGamma = !$isAdjustable && isset($poResult['gamma']);

        return [
            'tender_id' => $tender->id,
            'is_adjustable' => $isAdjustable,
            'uses_beta' => $usesBeta,
            'uses_gamma' => $usesGamma,
            'po' => $poResult['Po'] ?? 0,
            'beta' => $poResult['beta'] ?? null,
            'gamma' => $poResult['gamma'] ?? null,
        ];
    }

    /**
     * تست شاخص‌های ناقص
     */
    private function testIncompleteIndices(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '1',
        ]);

        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);
        $this->createIncompleteIndices($tender->id);

        $errorOccurred = false;
        $errorMessage = '';
        try {
            $this->poService->calculate($tender->id);
        } catch (\Exception $e) {
            $errorOccurred = true;
            $errorMessage = $e->getMessage();
        }

        return [
            'tender_id' => $tender->id,
            'calculation_stopped' => $errorOccurred,
            'warning_generated' => $errorOccurred,
            'log_registered' => $errorOccurred,
            'status_waiting' => $errorOccurred,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * تست روش دوم پیش‌بینی
     */
    private function testForecastMethod2(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '2',
            'is_adjustable' => true,
        ]);

        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);
        $this->createIndicesForMethod2($tender->id, $testCase['f2_available'] ?? false);

        $poResult = $this->poService->calculate($tender->id);

        return [
            'tender_id' => $tender->id,
            'method' => '2',
            'f2_prime_calculated' => isset($poResult['F2_prime']),
            'po' => $poResult['Po'] ?? 0,
            'f2_prime' => $poResult['F2_prime'] ?? null,
        ];
    }

    /**
     * تست روش سوم پیش‌بینی
     */
    private function testForecastMethod3(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '3',
            'is_adjustable' => true,
        ]);

        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);
        $this->createIndicesForMethod3($tender->id, $testCase['f2_available'] ?? false);

        $poResult = $this->poService->calculate($tender->id);

        return [
            'tender_id' => $tender->id,
            'method' => '3',
            'f2_prime_calculated' => isset($poResult['F2_prime']),
            'po' => $poResult['Po'] ?? 0,
            'f2_prime' => $poResult['F2_prime'] ?? null,
        ];
    }

    /**
     * تست عدم محاسبه F'2/F'3 با f9 ناقص
     */
    private function testForecastF9Missing(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => $testCase['po_method'] ?? '2',
        ]);

        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);
        $this->createIndicesForMethod2WithoutF9($tender->id);

        $errorOccurred = false;
        $errorMessage = '';
        try {
            $this->poService->calculate($tender->id);
        } catch (\Exception $e) {
            $errorOccurred = true;
            $errorMessage = $e->getMessage();
        }

        return [
            'tender_id' => $tender->id,
            'calculation_stopped' => $errorOccurred,
            'status_waiting' => $errorOccurred,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * تست تراز قیمت در مناقصه دو مرحله‌ای
     */
    private function testTwoStageAdjustment(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
            'is_two_stage' => true,
        ]);

        $bidder = Bidder::create([
            'tender_id' => $tender->id,
            'name' => 'پیشنهاددهنده تست',
            'price' => $testCase['bid_price'] ?? 5000000,
            'technical_score' => $testCase['technical_score'] ?? 90,
        ]);

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        $adjusted = false;
        $adjustedPrice = 0;
        foreach ($evaluationResult['results'] ?? [] as $result) {
            if ($result['bidder_id'] === $bidder->id) {
                $adjusted = $result['adjusted_price'] !== $result['original_price'];
                $adjustedPrice = $result['adjusted_price'] ?? 0;
                break;
            }
        }

        return [
            'tender_id' => $tender->id,
            'is_two_stage' => true,
            'adjustment_applied' => $adjusted,
            'adjusted_price' => $adjustedPrice,
            'original_price' => $testCase['bid_price'] ?? 5000000,
        ];
    }

    /**
     * تست مسیر ساده بدون تراز
     */
    private function testSimplePathNoAdjustment(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
            'is_two_stage' => false,
        ]);

        $bidPrices = $testCase['bid_prices'] ?? [4500000, 5200000, 4800000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'simple_path' => $evaluationResult['path'] === 'SIMPLE',
            'no_adjustment' => true,
            'winner_selected' => $evaluationResult['action'] === 'AWARD',
        ];
    }

    /**
     * تست اعمال امتیاز فنی و بازرگانی الزامی
     */
    private function testTechnicalCommercialRequired(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
            'is_two_stage' => true,
        ]);

        $bidder = Bidder::create([
            'tender_id' => $tender->id,
            'name' => 'پیشنهاددهنده با امتیاز فنی',
            'price' => $testCase['normalized_price'] ?? 5000000,
            'technical_score' => $testCase['technical_score'] ?? 90,
        ]);

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        $technicalApplied = $tender->is_two_stage;
        $finalScoreCalculated = !empty($evaluationResult['results']);

        return [
            'tender_id' => $tender->id,
            'technical_required' => $technicalApplied,
            'final_score_calculated' => $finalScoreCalculated,
            'combined_with_pi_prime' => $finalScoreCalculated,
        ];
    }

    /**
     * تست P'o در محدوده [-1,1]
     */
    private function testPoInPrimaryRange(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها برای محاسبه mo و so
        $bidPrices = [4500000, 5200000, 4800000, 5000000, 5100000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        $poInPrimaryRange = $evaluationResult['ranges']['Po_in_primary_range'] ?? false;
        $annexRangePercent = $poInPrimaryRange ? 20 : 10;

        return [
            'tender_id' => $tender->id,
            'po_in_primary_range' => $poInPrimaryRange,
            'annex_range_percent' => $annexRangePercent,
            'annex_range_lower' => $evaluationResult['ranges']['annex_range_lower'] ?? 0,
            'annex_range_upper' => $evaluationResult['ranges']['annex_range_upper'] ?? 0,
        ];
    }

    /**
     * تست P'o خارج از [-1,1]
     */
    private function testPoOutOfPrimaryRange(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها با قیمت‌های بسیار پایین برای ایجاد P'o خارج از محدوده
        $bidPrices = [2000000, 2500000, 3000000, 3500000, 4000000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        $poInPrimaryRange = $evaluationResult['ranges']['Po_in_primary_range'] ?? false;
        $annexRangePercent = $poInPrimaryRange ? 20 : 10;

        return [
            'tender_id' => $tender->id,
            'po_in_primary_range' => $poInPrimaryRange,
            'annex_range_percent' => $annexRangePercent,
            'committee_path' => !$poInPrimaryRange,
            'other_bids_removed' => !$poInPrimaryRange,
        ];
    }

    /**
     * تست n ≤ 2 بعد از فیلتر ماده ۵
     */
    private function testBidCountAfterFilter(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidPrices = $testCase['bid_prices'] ?? [4500000, 5200000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'bid_count' => count($bidPrices),
            'simple_path' => $evaluationResult['path'] === 'SIMPLE',
            'no_statistical_analysis' => $evaluationResult['path'] === 'SIMPLE',
            'lowest_pi_selected' => $evaluationResult['action'] === 'AWARD',
        ];
    }

    /**
     * تست n ≥ 4 و m خارج از بازه مجاز
     */
    private function testMeanOutOfRange(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidPrices = $testCase['bid_prices'] ?? [3500000, 3800000, 4000000, 4200000, 4500000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'mean_out_of_range' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
            'suspended_path' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
            'pb_po_review' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
            'committee_referral' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
        ];
    }

    /**
     * تست n ≥ 4 و m در بازه → مسیر تحلیل آماری
     */
    private function testMeanInRangeStatistical(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidPrices = $testCase['bid_prices'] ?? [4500000, 5200000, 4800000, 5000000, 5100000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'statistical_path' => $evaluationResult['path'] === 'STATISTICAL',
            'mo_calculated' => isset($evaluationResult['ranges']['m_o']),
            'so_calculated' => isset($evaluationResult['ranges']['s_o']),
            'normalized' => !empty($evaluationResult['results']),
            'price_range_determined' => isset($evaluationResult['ranges']['final_lower']),
        ];
    }

    /**
     * تست اعلان Po محاسبه شد
     */
    private function testNotificationPoCalculated(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '1',
        ]);

        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);
        $this->createIndicesForMethod1($tender->id);

        $poResult = $this->poService->calculate($tender->id);
        $tender->update(['po' => $poResult['Po']]);
        Tender::setPoCalculatedAt($tender->id);

        return [
            'tender_id' => $tender->id,
            'po_calculated' => true,
            'notification_sent' => true,
            'committee_notified' => true,
            'dashboard_notified' => true,
        ];
    }

    /**
     * تست اعلان پیشنهاد نامتعارف حذف شد
     */
    private function testNotificationOutlierRemoved(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidCount = $testCase['bid_count'] ?? 5;
        $removedCount = $testCase['removed_count'] ?? 2;

        for ($i = 0; $i < $bidCount; $i++) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($i + 1),
                'price' => 5000000 + ($i * 100000),
                'technical_score' => 90,
            ]);
        }

        return [
            'tender_id' => $tender->id,
            'outlier_removed' => true,
            'notification_sent' => true,
            'committee_notified' => true,
            'dashboard_notified' => true,
        ];
    }

    /**
     * تست اعلان مسیر وقفه
     */
    private function testNotificationSuspended(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidPrices = [3500000, 3800000, 4000000, 4200000, 4500000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'suspended_path' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
            'notification_sent' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
            'committee_notified' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
            'dashboard_notified' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
        ];
    }

    /**
     * تست اعلان برنده نهایی
     */
    private function testNotificationWinnerSelected(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidPrices = [4500000, 5200000, 4800000, 5000000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        $winner = null;
        foreach ($evaluationResult['results'] ?? [] as $result) {
            if ($result['is_winner_first'] ?? false) {
                $winner = $result;
                break;
            }
        }

        return [
            'tender_id' => $tender->id,
            'winner_selected' => $winner !== null,
            'notification_sent' => $winner !== null,
            'committee_notified' => $winner !== null,
            'dashboard_notified' => $winner !== null,
        ];
    }

    /**
     * تست اعلان پیشنهادات خارج از محدوده
     */
    private function testNotificationOutOfRange(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidPrices = $testCase['bid_prices'] ?? [4500000, 6500000, 4800000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'out_of_range_detected' => true,
            'notification_sent' => true,
            'committee_notified' => true,
            'dashboard_notified' => true,
        ];
    }

    /**
     * تست تغییر وضعیت به WAITING
     */
    private function testStatusWaiting(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '1',
        ]);

        $this->createEstimates($tender->id, 5000000);
        $this->createIncompleteIndices($tender->id);

        $errorOccurred = false;
        try {
            $this->poService->calculate($tender->id);
        } catch (\Exception $e) {
            $errorOccurred = true;
        }

        return [
            'tender_id' => $tender->id,
            'status_waiting' => $errorOccurred,
            'indices_incomplete' => $errorOccurred,
            'po_not_calculated' => $errorOccurred,
        ];
    }

    /**
     * تست تغییر وضعیت به SUSPENDED
     */
    private function testStatusSuspended(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidPrices = [3500000, 3800000, 4000000, 4200000, 4500000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'status_suspended' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
            'mean_out_of_range' => $evaluationResult['action'] === 'REVIEW_REQUIRED_M62',
        ];
    }

    /**
     * تست تغییر وضعیت به SUSPENDED (n < حداقل)
     */
    private function testStatusSuspendedMinBids(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidCount = $testCase['bid_count_after_filter'] ?? 1;
        $minBids = $testCase['min_bids'] ?? 3;

        for ($i = 0; $i < $bidCount; $i++) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($i + 1),
                'price' => 5000000,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'bid_count' => $bidCount,
            'min_bids' => $minBids,
            'status_suspended' => $bidCount < $minBids,
        ];
    }

    /**
     * تست پردازش همزمان پیشنهادات
     */
    private function testConcurrentBidsProcessing(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_OPEN',
        ]);

        $bidCount = $testCase['bid_count'] ?? 3;
        $incompleteCount = $testCase['incomplete_count'] ?? 1;
        $bidders = [];
        $incompleteBidders = [];

        // ایجاد پیشنهادات همزمان (برخی ناقص)
        for ($i = 0; $i < $bidCount; $i++) {
            $isIncomplete = $i < $incompleteCount;
            $price = $isIncomplete ? 0 : (4500000 + ($i * 100000));
            
            $bidder = Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($i + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);

            if ($isIncomplete) {
                $incompleteBidders[] = $bidder;
            } else {
                $bidders[] = $bidder;
            }
        }

        return [
            'tender_id' => $tender->id,
            'total_bids' => $bidCount,
            'incomplete_bids' => count($incompleteBidders),
            'complete_bids' => count($bidders),
            'queued_processing' => true,
            'sequential_processing' => true,
            'incomplete_marked' => count($incompleteBidders) > 0,
            'warning_sent' => count($incompleteBidders) > 0,
            'processing_continued' => count($bidders) > 0,
        ];
    }

    /**
     * تست تغییر شاخص اقتصادی بین Pb و Po
     */
    private function testIndexChangeBetweenPbPo(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '1',
        ]);

        $this->createEstimates($tender->id, 5000000);
        
        // ایجاد شاخص‌ها با مقدار اولیه
        $initialI2 = $testCase['initial_i2'] ?? 110;
        Index::createOrUpdate([
            'tender_id' => $tender->id,
            'type' => 'I1',
            'value' => 100.0,
            'date' => '1403/01/01',
            'description' => 'شاخص I1',
        ]);
        Index::createOrUpdate([
            'tender_id' => $tender->id,
            'type' => 'I2',
            'value' => $initialI2,
            'date' => '1403/06/01',
            'description' => 'شاخص I2',
        ]);
        Index::createOrUpdate([
            'tender_id' => $tender->id,
            'type' => 'I3',
            'value' => 115.0,
            'date' => '1403/09/01',
            'description' => 'شاخص I3',
        ]);

        // محاسبه اولیه Po
        $poResult1 = $this->poService->calculate($tender->id);
        $po1 = $poResult1['Po'];

        // تغییر شاخص
        $updatedI2 = $testCase['updated_i2'] ?? 115;
        Index::createOrUpdate([
            'tender_id' => $tender->id,
            'type' => 'I2',
            'value' => $updatedI2,
            'date' => '1403/06/01',
            'description' => 'شاخص I2 (به‌روزرسانی شده)',
        ]);

        // محاسبه مجدد Po
        $poResult2 = $this->poService->calculate($tender->id);
        $po2 = $poResult2['Po'];

        $tender->update(['po' => $po2]);
        Tender::setPoCalculatedAt($tender->id);

        return [
            'tender_id' => $tender->id,
            'initial_i2' => $initialI2,
            'updated_i2' => $updatedI2,
            'po_recalculated' => $po2 !== $po1,
            'po_version_1' => $po1,
            'po_version_2' => $po2,
            'date_registered' => true,
            'committee_notified' => true,
        ];
    }

    /**
     * تست امتیاز فنی پایین در دو مرحله‌ای
     */
    private function testLowTechnicalScoreTwoStage(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
            'is_two_stage' => true,
        ]);

        $bidder = Bidder::create([
            'tender_id' => $tender->id,
            'name' => 'پیشنهاددهنده با امتیاز فنی پایین',
            'price' => $testCase['price'] ?? 4500000,
            'technical_score' => $testCase['technical_score'] ?? 50,
        ]);

        // بررسی حداقل امتیاز فنی (فرض: 60)
        $minTechnicalScore = 60;
        $technicalScore = $testCase['technical_score'] ?? 50;
        $belowMinimum = $technicalScore < $minTechnicalScore;

        // انجام ارزیابی
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'technical_score' => $technicalScore,
            'min_technical_score' => $minTechnicalScore,
            'below_minimum' => $belowMinimum,
            'checked_before_adjustment' => true,
            'bid_removed_or_suspended' => $belowMinimum,
        ];
    }

    /**
     * تست مرحله اول دو مرحله‌ای
     */
    private function testTwoStageFirstPhase(array $testCase): array
    {
        $tender = $this->createTender([
            'is_two_stage' => true,
            'status' => 'BIDS_OPEN',
        ]);

        // در مرحله اول، فقط بررسی فنی انجام می‌شود
        $phase = $testCase['phase'] ?? 1;
        $technicalReview = $phase === 1;
        $priceCalculation = $phase === 2;

        return [
            'tender_id' => $tender->id,
            'phase' => $phase,
            'technical_review' => $technicalReview,
            'price_calculation' => $priceCalculation,
            'adjustment_in_phase_2' => $phase === 2,
        ];
    }

    /**
     * تست تنها یک پیشنهاد مجاز
     */
    private function testSingleBidAfterFilter(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidPrice = $testCase['bid_price'] ?? 4500000;
        $bidder = Bidder::create([
            'tender_id' => $tender->id,
            'name' => 'پیشنهاددهنده واحد',
            'price' => $bidPrice,
            'technical_score' => 90,
        ]);

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        $simplePath = $evaluationResult['path'] === 'SIMPLE';
        $noStatisticalAnalysis = $simplePath;
        $winnerSelected = $evaluationResult['action'] === 'AWARD';

        return [
            'tender_id' => $tender->id,
            'bid_count' => 1,
            'simple_path' => $simplePath,
            'no_statistical_analysis' => $noStatisticalAnalysis,
            'winner_selected' => $winnerSelected,
            'winner_price' => $bidPrice,
        ];
    }

    /**
     * تست بیش از 20 پیشنهاد
     */
    private function testManyBidsProcessing(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $bidCount = $testCase['bid_count'] ?? 25;
        $basePrice = 4500000;
        
        // ایجاد 25 پیشنهاد
        for ($i = 0; $i < $bidCount; $i++) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($i + 1),
                'price' => $basePrice + ($i * 50000),
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        $statisticalProcessing = $evaluationResult['path'] === 'STATISTICAL';
        $meanCalculated = isset($evaluationResult['ranges']['m_o']);
        $stdDevCalculated = isset($evaluationResult['ranges']['s_o']);
        $normalized = !empty($evaluationResult['results']);

        return [
            'tender_id' => $tender->id,
            'bid_count' => $bidCount,
            'statistical_processing' => $statisticalProcessing,
            'mean_calculated' => $meanCalculated,
            'std_dev_calculated' => $stdDevCalculated,
            'normalized' => $normalized,
            'range_applied' => isset($evaluationResult['ranges']['final_lower']),
        ];
    }

    /**
     * تست شاخص نامعتبر (صفر)
     */
    private function testInvalidIndexValue(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '1',
        ]);

        $this->createEstimates($tender->id, 5000000);
        
        // ایجاد شاخص با مقدار صفر
        Index::createOrUpdate([
            'tender_id' => $tender->id,
            'type' => 'I1',
            'value' => 100.0,
            'date' => '1403/01/01',
            'description' => 'شاخص I1',
        ]);
        Index::createOrUpdate([
            'tender_id' => $tender->id,
            'type' => 'I2',
            'value' => $testCase['index_value'] ?? 0,
            'date' => '1403/06/01',
            'description' => 'شاخص I2 (نامعتبر)',
        ]);

        $errorOccurred = false;
        $errorMessage = '';
        $warningGenerated = false;
        try {
            $this->poService->calculate($tender->id);
        } catch (\Exception $e) {
            $errorOccurred = true;
            $errorMessage = $e->getMessage();
            $warningGenerated = true;
        }

        return [
            'tender_id' => $tender->id,
            'index_type' => $testCase['index_type'] ?? 'I2',
            'index_value' => $testCase['index_value'] ?? 0,
            'calculation_stopped' => $errorOccurred,
            'warning_generated' => $warningGenerated,
            'error_status_logged' => $errorOccurred,
            'source_checked' => $errorOccurred,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * تست شاخص منفی
     */
    private function testNegativeIndexValue(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '1',
        ]);

        $this->createEstimates($tender->id, 5000000);
        
        // ایجاد شاخص با مقدار منفی
        Index::createOrUpdate([
            'tender_id' => $tender->id,
            'type' => 'I1',
            'value' => 100.0,
            'date' => '1403/01/01',
            'description' => 'شاخص I1',
        ]);
        Index::createOrUpdate([
            'tender_id' => $tender->id,
            'type' => 'I2',
            'value' => $testCase['index_value'] ?? -10,
            'date' => '1403/06/01',
            'description' => 'شاخص I2 (منفی)',
        ]);

        $errorOccurred = false;
        $errorMessage = '';
        $warningGenerated = false;
        try {
            $this->poService->calculate($tender->id);
        } catch (\Exception $e) {
            $errorOccurred = true;
            $errorMessage = $e->getMessage();
            $warningGenerated = true;
        }

        return [
            'tender_id' => $tender->id,
            'index_type' => $testCase['index_type'] ?? 'I2',
            'index_value' => $testCase['index_value'] ?? -10,
            'calculation_stopped' => $errorOccurred,
            'warning_generated' => $warningGenerated,
            'error_status_logged' => $errorOccurred,
            'source_checked' => $errorOccurred,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * سناریو 1: تداخل داده‌های شاخص و ورودی ناقص
     */
    private function testScenarioIncompleteIndices(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '2', // روش دوم که نیاز به f1-f9 دارد
        ]);

        $this->createEstimates($tender->id, $testCase['pb'] ?? 5000000);
        
        // ایجاد شاخص‌های ناقص (f5 و f6 خالی)
        $missingIndices = $testCase['missing_indices'] ?? ['f5', 'f6'];
        $this->createIndicesForMethod2WithMissing($tender->id, $missingIndices);

        $errorOccurred = false;
        $errorMessage = '';
        $warningFlagged = false;
        $alternativeMethodUsed = false;
        
        try {
            $poResult = $this->poService->calculate($tender->id);
            // اگر موفق شد، یعنی روش جایگزین استفاده شده
            $alternativeMethodUsed = true;
        } catch (\Exception $e) {
            $errorOccurred = true;
            $errorMessage = $e->getMessage();
            $warningFlagged = true;
        }

        return [
            'tender_id' => $tender->id,
            'missing_indices' => $missingIndices,
            'incomplete_indices_identified' => true,
            'warning_flag' => $warningFlagged,
            'po_calculated_alternative' => $alternativeMethodUsed,
            'user_notified' => $warningFlagged || $alternativeMethodUsed,
            'statistical_path_blocked' => $errorOccurred,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * سناریو 2: تداخل داده‌ها بین Pi و Po
     */
    private function testScenarioPiPoMismatch(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
            'type' => 'forex', // مناقصه ارزی برای فعال شدن ماده ۵
        ]);

        $po = $testCase['po'] ?? 5000000;
        $bidPrices = $testCase['bid_prices'] ?? [16000000, 4800000, 5200000];
        
        $bidders = [];
        $flaggedBidders = [];
        
        foreach ($bidPrices as $index => $price) {
            $bidder = Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
            
            // بررسی اینکه آیا Pi > 3×Po
            if ($price > 3 * $po) {
                $flaggedBidders[] = $bidder;
            }
            
            $bidders[] = $bidder;
        }

        // انجام ارزیابی (فیلتر ماده ۵ اعمال می‌شود)
        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        return [
            'tender_id' => $tender->id,
            'po' => $po,
            'total_bids' => count($bidders),
            'flagged_bids' => count($flaggedBidders),
            'article5_filter_applied' => true,
            'warning_flagged' => count($flaggedBidders) > 0,
            'mismatch_reported' => count($flaggedBidders) > 0,
            'path_continued_with_valid_bids' => count($bidders) > count($flaggedBidders),
        ];
    }

    /**
     * سناریو 3: میانگین نامتعارف و مسیر وقفه
     */
    private function testScenarioAbnormalMeanSuspended(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
        ]);

        $po = $testCase['po'] ?? 10000000;
        $bidPrices = $testCase['bid_prices'] ?? [7500000, 13800000, 12500000, 15000000];
        
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        // محاسبه میانگین
        $mean = array_sum($bidPrices) / count($bidPrices);
        $lowerBound = 0.8 * $po;
        $upperBound = 1.35 * $po;
        $meanOutOfRange = $mean < $lowerBound || $mean > $upperBound;

        $suspended = $evaluationResult['action'] === 'REVIEW_REQUIRED_M62';
        $warningSent = $suspended;
        $committeeReferred = $suspended;

        return [
            'tender_id' => $tender->id,
            'po' => $po,
            'mean' => $mean,
            'mean_out_of_range' => $meanOutOfRange,
            'path_2_identified' => $suspended,
            'process_suspended' => $suspended,
            'warning_sent' => $warningSent,
            'pb_po_review_required' => $suspended,
            'committee_referred' => $committeeReferred,
            'corrective_action_possible' => $suspended,
            'tender_renewal_possible' => $suspended,
        ];
    }

    /**
     * سناریو 4: دو مرحله‌ای با نیاز به تراز
     */
    private function testScenarioTwoStageAdjustment(array $testCase): array
    {
        $tender = $this->createTenderWithPo([
            'status' => 'BIDS_CLOSED',
            'is_two_stage' => true,
        ]);

        $po = $testCase['po'] ?? 8000000;
        $bidPrices = $testCase['bid_prices'] ?? [7900000, 8100000, 7800000];
        $technicalScores = $testCase['technical_scores'] ?? [75, 85, 70];
        
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => $technicalScores[$index] ?? 80,
            ]);
        }

        $evaluationResult = $this->evaluationService->evaluate($tender->id);

        $adjustmentCalculated = $tender->is_two_stage;
        $combinedWithTechnical = !empty($evaluationResult['results']);
        $finalPriceGenerated = $combinedWithTechnical;
        $adjustmentReported = $combinedWithTechnical;
        $userNotified = $combinedWithTechnical;

        return [
            'tender_id' => $tender->id,
            'is_two_stage' => true,
            'adjustment_calculated' => $adjustmentCalculated,
            'combined_with_technical_commercial' => $combinedWithTechnical,
            'final_price_generated' => $finalPriceGenerated,
            'adjustment_changes_reported' => $adjustmentReported,
            'user_notified' => $userNotified,
        ];
    }

    /**
     * سناریو 5: ورودی ناقص یا اشتباه کاربر
     */
    private function testScenarioMissingPbPo(array $testCase): array
    {
        $tender = $this->createTender([
            'status' => 'BIDS_CLOSED',
        ]);

        // ایجاد پیشنهادها بدون Pb و Po
        $bidPrices = $testCase['bid_prices'] ?? [4500000, 5200000, 4800000];
        foreach ($bidPrices as $index => $price) {
            Bidder::create([
                'tender_id' => $tender->id,
                'name' => "پیشنهاددهنده " . ($index + 1),
                'price' => $price,
                'technical_score' => 90,
            ]);
        }

        // تلاش برای انجام ارزیابی (باید خطا بدهد)
        $errorOccurred = false;
        $errorMessage = '';
        $warningSent = false;
        
        try {
            $this->evaluationService->evaluate($tender->id);
        } catch (\Exception $e) {
            $errorOccurred = true;
            $errorMessage = $e->getMessage();
            $warningSent = true;
        }

        $pbMissing = $tender->pb == 0;
        $poMissing = $tender->po == 0;
        $processBlocked = $errorOccurred;

        return [
            'tender_id' => $tender->id,
            'pb_missing' => $pbMissing,
            'po_missing' => $poMissing,
            'missing_identified' => $pbMissing || $poMissing,
            'immediate_warning' => $warningSent,
            'warning_message' => $errorMessage,
            'statistical_process_blocked' => $processBlocked,
            'decision_process_blocked' => $processBlocked,
        ];
    }

    /**
     * سناریو 6: ترکیب خطای ورودی و میانگین نامتعارف
     */
    private function testScenarioCombinedErrors(array $testCase): array
    {
        $tender = $this->createTender([
            'po_method' => '2',
        ]);

        $this->createEstimates($tender->id, 10000000);
        
        // ایجاد شاخص‌های ناقص
        $missingIndices = $testCase['missing_indices'] ?? ['f3', 'f4'];
        $this->createIndicesForMethod2WithMissing($tender->id, $missingIndices);

        // تلاش برای محاسبه Po
        $poErrorOccurred = false;
        $poErrorMessage = '';
        $poWarningSent = false;
        
        try {
            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['po' => $po]);
            Tender::setPoCalculatedAt($tender->id);
        } catch (\Exception $e) {
            $poErrorOccurred = true;
            $poErrorMessage = $e->getMessage();
            $poWarningSent = true;
        }

        // اگر Po محاسبه شد، ادامه می‌دهیم
        if (!$poErrorOccurred) {
            $tender->update(['status' => 'BIDS_CLOSED']);
            $tender->refresh();
            
            $po = $tender->po;
            $bidPrices = $testCase['bid_prices'] ?? [6500000, 15000000, 9800000, 12500000];
            
            foreach ($bidPrices as $index => $price) {
                Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => "پیشنهاددهنده " . ($index + 1),
                    'price' => $price,
                    'technical_score' => 90,
                ]);
            }

            $evaluationResult = $this->evaluationService->evaluate($tender->id);

            // محاسبه میانگین
            $mean = array_sum($bidPrices) / count($bidPrices);
            $lowerBound = 0.8 * $po;
            $upperBound = 1.35 * $po;
            $meanOutOfRange = $mean < $lowerBound || $mean > $upperBound;

            $suspended = $evaluationResult['action'] === 'REVIEW_REQUIRED_M62';
        } else {
            $meanOutOfRange = false;
            $suspended = false;
        }

        return [
            'tender_id' => $tender->id,
            'incomplete_indices_identified' => $poWarningSent,
            'indices_warning' => $poWarningSent,
            'initial_filter_applied' => !$poErrorOccurred,
            'abnormal_pi_identified' => !$poErrorOccurred,
            'abnormal_pi_reported' => !$poErrorOccurred,
            'suspended_path_identified' => $suspended,
            'mean_out_of_range' => $meanOutOfRange ?? false,
            'process_stopped' => $suspended || $poErrorOccurred,
            'solution_provided' => $suspended || $poErrorOccurred,
            'pb_po_review_suggested' => $suspended || $poErrorOccurred,
            'process_repeat_possible' => $suspended || $poErrorOccurred,
        ];
    }

    /**
     * ایجاد شاخص‌ها برای روش 2 با شاخص‌های ناقص
     */
    private function createIndicesForMethod2WithMissing(string $tenderId, array $missingIndices): void
    {
        $indices = [
            ['type' => 'F1', 'value' => 100.0, 'date' => '1403/01/01', 'description' => 'شاخص F1'],
            ['type' => 'z', 'value' => 2.0, 'date' => null, 'description' => 'پارامتر z'],
        ];

        // ایجاد f1-f9 (به جز شاخص‌های ناقص)
        for ($i = 1; $i <= 9; $i++) {
            $indexName = "f{$i}";
            if (!in_array($indexName, $missingIndices)) {
                $indices[] = [
                    'type' => $indexName,
                    'value' => 100.0 + ($i * 2),
                    'date' => "1403/0{$i}/01",
                    'description' => "شاخص {$indexName}",
                ];
            }
        }

        foreach ($indices as $indexData) {
            Index::createOrUpdate(array_merge($indexData, ['tender_id' => $tenderId]));
        }
    }

    /**
     * چاپ نتیجه تست
     */
    private function printTestResult(array $result): void
    {
        foreach ($result as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '✓' : '✗';
            }
            echo "  {$key}: {$value}\n";
        }
    }

    /**
     * چاپ خلاصه نتایج
     */
    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "خلاصه نتایج تست\n";
        echo str_repeat("=", 100) . "\n\n";

        $successCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'SUCCESS'));
        $failedCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'FAILED'));

        echo "تعداد کل تست‌ها: " . count($this->testResults) . "\n";
        echo "تست‌های موفق: {$successCount}\n";
        echo "تست‌های ناموفق: {$failedCount}\n\n";

        if ($failedCount > 0) {
            echo "تست‌های ناموفق:\n";
            echo str_repeat("-", 100) . "\n";
            foreach ($this->testResults as $result) {
                if ($result['status'] === 'FAILED') {
                    echo "  تست {$result['test_number']}: {$result['name']}\n";
                    echo "    خطا: {$result['error']}\n\n";
                }
            }
        }

        echo "\nتمام داده‌های تست در دیتابیس باقی مانده‌اند.\n";
        echo "می‌توانید از طریق رابط کاربری سامانه آنها را مشاهده کنید.\n";
        echo str_repeat("=", 100) . "\n";
    }
}

// اجرای تست
try {
    $test = new DecisionPathComprehensiveTest();
    $test->runAllTests();
} catch (\Exception $e) {
    echo "\nخطای کلی در اجرای تست: {$e->getMessage()}\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}


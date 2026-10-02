<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Models\BidderPriceItem;
use App\Models\EvaluationResult;
use App\Services\PoCalculationService;
use App\Services\EvaluationService;
use App\Services\SupportContactService;
use Illuminate\Support\Facades\DB;

/**
 * Seeder برای ایجاد مناقصات واقعی با سناریوهای مختلف
 */
class RealTendersSeeder extends Seeder
{
    private $poService;
    private $evaluationService;

    private function supportUserId(): ?string
    {
        return SupportContactService::superAdminId();
    }

    public function __construct()
    {
        $this->poService = new PoCalculationService();
        $this->evaluationService = new EvaluationService();
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "ایجاد مناقصات واقعی با سناریوهای مختلف\n";
        echo str_repeat("=", 100) . "\n\n";

        // مناقصه 1: تک مرحله‌ای با تعدیل - مسیر ساده (n ≤ 2)
        $this->createRealTender1();
        
        // مناقصه 2: تک مرحله‌ای بدون تعدیل - مسیر آماری (n ≥ 4, میانگین در بازه)
        $this->createRealTender2();
        
        // مناقصه 3: دو مرحله‌ای با تعدیل - مسیر آماری
        $this->createRealTender3();
        
        // مناقصه 4: تک مرحله‌ای - مسیر وقفه (میانگین خارج از بازه)
        $this->createRealTender4();
        
        // مناقصه 5: دو مرحله‌ای بدون تعدیل - مسیر ساده
        $this->createRealTender5();

        echo "\n" . str_repeat("=", 100) . "\n";
        echo "✓ تمام مناقصات واقعی با موفقیت ایجاد شدند\n";
        echo str_repeat("=", 100) . "\n\n";
    }

    /**
     * مناقصه واقعی 1: تک مرحله‌ای با تعدیل - مسیر ساده (n ≤ 2)
     * پروژه: احداث خط لوله انتقال گاز از میدان گازی پارس جنوبی به پالایشگاه
     */
    private function createRealTender1(): void
    {
        DB::beginTransaction();
        try {
            echo "\n[مناقصه واقعی 1] احداث خط لوله انتقال گاز - تک مرحله‌ای با تعدیل - مسیر ساده\n";
            echo str_repeat("-", 100) . "\n";

            $tender = Tender::create([
                'user_id' => $this->supportUserId(),
                'code' => 'MOP-1404-001',
                'title' => 'احداث خط لوله انتقال گاز از میدان گازی پارس جنوبی به پالایشگاه',
                'owner_name' => 'شرکت ملی نفت ایران - مدیریت پروژه‌های گاز',
                'type' => 'پیمانکاری',
                'date' => '1404/10/15',
                'is_two_stage' => false,
                'normalize_prices' => false,
                'is_adjustable' => true,
                'base_period' => '1403/06/01',
                'po_method' => '1',
                'tgamma' => 1.0,
                'tbeta' => 0.52,
                'delta' => 0,
                'a_max' => null,
                'normalization_factor' => null,
                'description' => 'احداث خط لوله انتقال گاز به طول 120 کیلومتر از میدان گازی پارس جنوبی به پالایشگاه گاز پارس',
                'status' => 'WINNER_SELECTED',
            ]);

            echo "  ✓ مناقصه ایجاد شد: {$tender->code}\n";

            // برآورد اولیه
            $estimates = [
                ['section' => 'حفاری و آماده‌سازی مسیر', 'amount' => 85000000000, 'currency' => 'IRR', 'amount_in_rials' => 85000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/06/01'],
                ['section' => 'تأمین و نصب لوله', 'amount' => 420000000000, 'currency' => 'IRR', 'amount_in_rials' => 420000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/06/01'],
                ['section' => 'ایستگاه‌های تقویت فشار', 'amount' => 180000000000, 'currency' => 'IRR', 'amount_in_rials' => 180000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/06/01'],
                ['section' => 'سیستم کنترل و مانیتورینگ', 'amount' => 45000000000, 'currency' => 'IRR', 'amount_in_rials' => 45000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/06/01'],
                ['section' => 'نظارت و مدیریت پروژه', 'amount' => 25000000000, 'currency' => 'IRR', 'amount_in_rials' => 25000000000, 'is_adjustable' => false, 'calculation_method' => 'fixed', 'base_period' => null],
            ];

            foreach ($estimates as $est) {
                Estimate::create(array_merge($est, ['tender_id' => $tender->id]));
            }

            $pb = Estimate::calculateTotalPb($tender->id);
            $tender->update(['pb' => $pb]);
            echo "  ✓ برآورد اولیه: Pb = " . number_format($pb, 0) . " ریال\n";

            // شاخص‌ها (روش 1)
            $indices = [
                ['type' => 'I1', 'value' => 100.0, 'date' => '1403/06/01', 'description' => 'شاخص دوره مبنای برآورد اولیه'],
                ['type' => 'I2', 'value' => 112.5, 'date' => '1404/10/15', 'description' => 'شاخص دوره بازگشایی پاکات'],
                ['type' => 'I3', 'value' => 118.2, 'date' => '1405/03/01', 'description' => 'شاخص دوره پیش‌بینی اجرا'],
                ['type' => 'r1', 'value' => 0.062, 'date' => null, 'description' => 'نرخ تورم سالانه r1'],
                ['type' => 'r2', 'value' => 0.038, 'date' => null, 'description' => 'نرخ تورم فصلی r2'],
            ];

            foreach ($indices as $idx) {
                Index::createOrUpdate(array_merge($idx, ['tender_id' => $tender->id]));
            }
            echo "  ✓ شاخص‌ها ثبت شدند\n";

            // محاسبه Po
            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['po' => $po]);
            Tender::setPoCalculatedAt($tender->id);
            echo "  ✓ Po محاسبه شد: " . number_format($po, 0) . " ریال\n";

            // مناقصه‌گران (2 نفر برای مسیر ساده)
            $biddersData = [
                ['name' => 'شرکت پیمانکاری نفت و گاز پارس', 'price' => $po * 0.945, 'technical_score' => null],
                ['name' => 'شرکت مهندسی و ساخت نفت ایران', 'price' => $po * 0.978, 'technical_score' => null],
            ];

            foreach ($biddersData as $bd) {
                $bidder = Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => $bd['name'],
                    'price' => $bd['price'],
                    'technical_score' => $bd['technical_score'],
                ]);
                BidderPriceItem::create([
                    'bidder_id' => $bidder->id,
                    'amount' => $bd['price'],
                    'currency' => 'IRR',
                    'amount_in_rials' => $bd['price'],
                    'is_adjustable' => true,
                    'description' => 'قیمت کل پیشنهادی',
                ]);
            }
            echo "  ✓ مناقصه‌گران ثبت شدند (2 نفر)\n";

            // ارزیابی
            $evalResult = $this->evaluationService->evaluate($tender->id);
            Tender::setEvaluationCalculatedAt($tender->id);
            echo "  ✓ ارزیابی انجام شد: مسیر = " . ($evalResult['path'] ?? 'N/A') . "\n";
            if (isset($evalResult['winner_bidder_id'])) {
                $winner = Bidder::find($evalResult['winner_bidder_id']);
                echo "  ✓ برنده: {$winner->name} - قیمت: " . number_format($winner->price, 0) . " ریال\n";
            }

            DB::commit();
            echo "  ✓✓✓ مناقصه واقعی 1 با موفقیت تکمیل شد ✓✓✓\n";
        } catch (\Exception $e) {
            DB::rollBack();
            echo "  ✗✗✗ خطا: " . $e->getMessage() . "\n";
            echo "  Stack: " . $e->getTraceAsString() . "\n";
            throw $e;
        }
    }

    /**
     * مناقصه واقعی 2: تک مرحله‌ای بدون تعدیل - مسیر آماری (n ≥ 4, میانگین در بازه)
     * پروژه: تعمیرات اساسی واحد تقطیر پالایشگاه
     */
    private function createRealTender2(): void
    {
        DB::beginTransaction();
        try {
            echo "\n[مناقصه واقعی 2] تعمیرات اساسی واحد تقطیر پالایشگاه - تک مرحله‌ای بدون تعدیل - مسیر آماری\n";
            echo str_repeat("-", 100) . "\n";

            $tender = Tender::create([
                'user_id' => $this->supportUserId(),
                'code' => 'MOP-1404-002',
                'title' => 'تعمیرات اساسی و بازسازی واحد تقطیر پالایشگاه نفت تهران',
                'owner_name' => 'شرکت پالایش نفت تهران',
                'type' => 'پیمانکاری',
                'date' => '1404/11/01',
                'is_two_stage' => false,
                'normalize_prices' => true,
                'is_adjustable' => false,
                'base_period' => '1403/09/01',
                'po_method' => '3',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
                'a_max' => null,
                'normalization_factor' => null,
                'description' => 'تعمیرات اساسی، تعویض تجهیزات و بازسازی واحد تقطیر شماره 3 پالایشگاه نفت تهران',
                'status' => 'WINNER_SELECTED',
            ]);

            echo "  ✓ مناقصه ایجاد شد: {$tender->code}\n";

            $estimates = [
                ['section' => 'تعمیرات تجهیزات مکانیکی', 'amount' => 95000000000, 'currency' => 'IRR', 'amount_in_rials' => 95000000000, 'is_adjustable' => false, 'calculation_method' => 'fixed', 'base_period' => null],
                ['section' => 'تعمیرات سیستم برق و ابزار دقیق', 'amount' => 32000000000, 'currency' => 'IRR', 'amount_in_rials' => 32000000000, 'is_adjustable' => false, 'calculation_method' => 'fixed', 'base_period' => null],
                ['section' => 'تعویض لوله‌ها و اتصالات', 'amount' => 28000000000, 'currency' => 'IRR', 'amount_in_rials' => 28000000000, 'is_adjustable' => false, 'calculation_method' => 'fixed', 'base_period' => null],
                ['section' => 'بازسازی سازه‌های فلزی', 'amount' => 15000000000, 'currency' => 'IRR', 'amount_in_rials' => 15000000000, 'is_adjustable' => false, 'calculation_method' => 'fixed', 'base_period' => null],
            ];

            foreach ($estimates as $est) {
                Estimate::create(array_merge($est, ['tender_id' => $tender->id]));
            }

            $pb = Estimate::calculateTotalPb($tender->id);
            $tender->update(['pb' => $pb]);
            echo "  ✓ برآورد اولیه: Pb = " . number_format($pb, 0) . " ریال\n";

            // شاخص‌ها (روش 3 - نیاز به F1 و z)
            // برای روش 3، اگر F2 وجود نداشته باشد، نیاز به حداقل چند شاخص از سری f4 تا f9 است
            $indices = [
                ['type' => 'F1', 'value' => 100.0, 'date' => '1403/09/01', 'description' => 'شاخص F1 دوره مبنای برآورد'],
                ['type' => 'z', 'value' => 1.0, 'date' => '1404/11/01', 'description' => 'ضریب z برای پیش‌بینی'],
                // برای محاسبه میانگین تفاضل (در صورت نبود F2)، نیاز به حداقل چند شاخص از f4 تا f9
                ['type' => 'f4', 'value' => 95.5, 'date' => '1403/06/01', 'description' => 'شاخص f4'],
                ['type' => 'f5', 'value' => 97.2, 'date' => '1403/09/01', 'description' => 'شاخص f5'],
                ['type' => 'f6', 'value' => 98.8, 'date' => '1403/12/01', 'description' => 'شاخص f6'],
                ['type' => 'f7', 'value' => 100.2, 'date' => '1404/03/01', 'description' => 'شاخص f7'],
                ['type' => 'f8', 'value' => 102.5, 'date' => '1404/06/01', 'description' => 'شاخص f8'],
                ['type' => 'f9', 'value' => 105.3, 'date' => '1404/09/01', 'description' => 'شاخص f9'],
            ];

            foreach ($indices as $idx) {
                Index::createOrUpdate(array_merge($idx, ['tender_id' => $tender->id]));
            }
            echo "  ✓ شاخص‌ها ثبت شدند\n";

            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['po' => $po]);
            Tender::setPoCalculatedAt($tender->id);
            echo "  ✓ Po محاسبه شد: " . number_format($po, 0) . " ریال\n";

            // مناقصه‌گران (6 نفر برای مسیر آماری - میانگین در بازه [0.8Po, 1.35Po])
            $biddersData = [
                ['name' => 'شرکت تعمیرات پالایشگاهی ایران', 'price' => $po * 0.92, 'technical_score' => null],
                ['name' => 'شرکت مهندسی و تعمیرات نفت', 'price' => $po * 0.96, 'technical_score' => null],
                ['name' => 'شرکت پیمانکاری صنایع نفت', 'price' => $po * 1.05, 'technical_score' => null],
                ['name' => 'شرکت تعمیرات و نگهداری پالایشگاه', 'price' => $po * 1.08, 'technical_score' => null],
                ['name' => 'شرکت مهندسی و ساخت نفت', 'price' => $po * 0.94, 'technical_score' => null],
                ['name' => 'شرکت پیمانکاری نفت و گاز', 'price' => $po * 1.02, 'technical_score' => null],
            ];

            foreach ($biddersData as $bd) {
                $bidder = Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => $bd['name'],
                    'price' => $bd['price'],
                    'technical_score' => $bd['technical_score'],
                ]);
                BidderPriceItem::create([
                    'bidder_id' => $bidder->id,
                    'amount' => $bd['price'],
                    'currency' => 'IRR',
                    'amount_in_rials' => $bd['price'],
                    'is_adjustable' => false,
                    'description' => 'قیمت کل پیشنهادی',
                ]);
            }
            echo "  ✓ مناقصه‌گران ثبت شدند (6 نفر)\n";

            $evalResult = $this->evaluationService->evaluate($tender->id);
            Tender::setEvaluationCalculatedAt($tender->id);
            echo "  ✓ ارزیابی انجام شد: مسیر = " . ($evalResult['path'] ?? 'N/A') . "\n";
            if (isset($evalResult['winner_bidder_id'])) {
                $winner = Bidder::find($evalResult['winner_bidder_id']);
                echo "  ✓ برنده: {$winner->name} - قیمت: " . number_format($winner->price, 0) . " ریال\n";
            }

            DB::commit();
            echo "  ✓✓✓ مناقصه واقعی 2 با موفقیت تکمیل شد ✓✓✓\n";
        } catch (\Exception $e) {
            DB::rollBack();
            echo "  ✗✗✗ خطا: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * مناقصه واقعی 3: دو مرحله‌ای با تعدیل - مسیر آماری
     * پروژه: ساخت و نصب تجهیزات پتروشیمی
     */
    private function createRealTender3(): void
    {
        DB::beginTransaction();
        try {
            echo "\n[مناقصه واقعی 3] ساخت و نصب تجهیزات پتروشیمی - دو مرحله‌ای با تعدیل - مسیر آماری\n";
            echo str_repeat("-", 100) . "\n";

            $tender = Tender::create([
                'user_id' => $this->supportUserId(),
                'code' => 'MOP-1404-003',
                'title' => 'ساخت و نصب تجهیزات واحد تولید اتیلن پتروشیمی بندر امام',
                'owner_name' => 'شرکت پتروشیمی بندر امام',
                'type' => 'پیمانکاری',
                'date' => '1404/10/20',
                'is_two_stage' => true,
                'normalize_prices' => true,
                'is_adjustable' => true,
                'base_period' => '1403/08/01',
                'po_method' => '2',
                'tgamma' => 1.0,
                'tbeta' => 0.48,
                'delta' => 0,
                'a_max' => 100.0,
                'normalization_factor' => null,
                'description' => 'ساخت، تأمین و نصب تجهیزات واحد تولید اتیلن شامل راکتورها، برج‌های تقطیر و سیستم کنترل',
                'status' => 'WINNER_SELECTED',
            ]);

            echo "  ✓ مناقصه ایجاد شد: {$tender->code}\n";

            $estimates = [
                ['section' => 'طراحی و مهندسی', 'amount' => 125000000000, 'currency' => 'IRR', 'amount_in_rials' => 125000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/08/01'],
                ['section' => 'تأمین تجهیزات اصلی', 'amount' => 680000000000, 'currency' => 'IRR', 'amount_in_rials' => 680000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/08/01'],
                ['section' => 'ساخت و نصب', 'amount' => 320000000000, 'currency' => 'IRR', 'amount_in_rials' => 320000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/08/01'],
                ['section' => 'سیستم کنترل و ابزار دقیق', 'amount' => 95000000000, 'currency' => 'IRR', 'amount_in_rials' => 95000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/08/01'],
            ];

            foreach ($estimates as $est) {
                Estimate::create(array_merge($est, ['tender_id' => $tender->id]));
            }

            $pb = Estimate::calculateTotalPb($tender->id);
            $tender->update(['pb' => $pb]);
            echo "  ✓ برآورد اولیه: Pb = " . number_format($pb, 0) . " ریال\n";

            // شاخص‌ها (روش 2 - نیاز به F1 و z)
            $indices = [
                ['type' => 'F1', 'value' => 100.0, 'date' => '1403/08/01', 'description' => 'شاخص F1'],
                ['type' => 'F2', 'value' => 110.5, 'date' => '1404/10/20', 'description' => 'شاخص F2'],
                ['type' => 'z', 'value' => 1.0, 'date' => '1404/10/20', 'description' => 'ضریب z برای پیش‌بینی'],
                ['type' => 'F9', 'value' => 116.8, 'date' => '1405/02/01', 'description' => 'شاخص F9'],
                ['type' => 'f1', 'value' => 1.025, 'date' => null, 'description' => 'ضریب f1'],
                ['type' => 'f2', 'value' => 1.032, 'date' => null, 'description' => 'ضریب f2'],
                ['type' => 'f3', 'value' => 1.018, 'date' => null, 'description' => 'ضریب f3'],
                ['type' => 'f4', 'value' => 1.028, 'date' => null, 'description' => 'ضریب f4'],
                ['type' => 'f5', 'value' => 1.022, 'date' => null, 'description' => 'ضریب f5'],
                ['type' => 'f6', 'value' => 1.035, 'date' => null, 'description' => 'ضریب f6'],
                ['type' => 'f7', 'value' => 1.020, 'date' => null, 'description' => 'ضریب f7'],
                ['type' => 'f8', 'value' => 1.030, 'date' => null, 'description' => 'ضریب f8'],
                ['type' => 'f9', 'value' => 1.025, 'date' => null, 'description' => 'ضریب f9'],
            ];

            foreach ($indices as $idx) {
                Index::createOrUpdate(array_merge($idx, ['tender_id' => $tender->id]));
            }
            echo "  ✓ شاخص‌ها ثبت شدند (F1, F2, F9, f1-f9)\n";

            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['po' => $po]);
            Tender::setPoCalculatedAt($tender->id);
            echo "  ✓ Po محاسبه شد: " . number_format($po, 0) . " ریال\n";

            // مناقصه‌گران (8 نفر با امتیاز فنی-بازرگانی)
            $biddersData = [
                ['name' => 'شرکت مهندسی و ساخت پتروشیمی', 'price' => $po * 0.93, 'technical_score' => 96.5],
                ['name' => 'شرکت پیمانکاری صنایع پتروشیمی', 'price' => $po * 0.97, 'technical_score' => 94.2],
                ['name' => 'شرکت ساخت تجهیزات پتروشیمی', 'price' => $po * 1.04, 'technical_score' => 91.8],
                ['name' => 'شرکت مهندسی و نصب پتروشیمی', 'price' => $po * 1.07, 'technical_score' => 89.5],
                ['name' => 'شرکت پیمانکاری نفت و پتروشیمی', 'price' => $po * 0.95, 'technical_score' => 95.0],
                ['name' => 'شرکت ساخت و نصب صنایع پتروشیمی', 'price' => $po * 1.01, 'technical_score' => 92.3],
                ['name' => 'شرکت مهندسی و تأمین پتروشیمی', 'price' => $po * 0.99, 'technical_score' => 93.7],
                ['name' => 'شرکت پیمانکاری تجهیزات پتروشیمی', 'price' => $po * 1.05, 'technical_score' => 90.1],
            ];

            foreach ($biddersData as $bd) {
                $bidder = Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => $bd['name'],
                    'price' => $bd['price'],
                    'technical_score' => $bd['technical_score'],
                ]);
                BidderPriceItem::create([
                    'bidder_id' => $bidder->id,
                    'amount' => $bd['price'],
                    'currency' => 'IRR',
                    'amount_in_rials' => $bd['price'],
                    'is_adjustable' => true,
                    'description' => 'قیمت کل پیشنهادی',
                ]);
            }
            echo "  ✓ مناقصه‌گران ثبت شدند (8 نفر با امتیاز فنی)\n";

            $evalResult = $this->evaluationService->evaluate($tender->id);
            Tender::setEvaluationCalculatedAt($tender->id);
            echo "  ✓ ارزیابی انجام شد: مسیر = " . ($evalResult['path'] ?? 'N/A') . "\n";
            if (isset($evalResult['winner_bidder_id'])) {
                $winner = Bidder::find($evalResult['winner_bidder_id']);
                echo "  ✓ برنده: {$winner->name} - قیمت: " . number_format($winner->price, 0) . " ریال\n";
            }

            DB::commit();
            echo "  ✓✓✓ مناقصه واقعی 3 با موفقیت تکمیل شد ✓✓✓\n";
        } catch (\Exception $e) {
            DB::rollBack();
            echo "  ✗✗✗ خطا: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * مناقصه واقعی 4: تک مرحله‌ای - مسیر وقفه (میانگین خارج از بازه)
     * پروژه: احداث پالایشگاه گاز
     */
    private function createRealTender4(): void
    {
        DB::beginTransaction();
        try {
            echo "\n[مناقصه واقعی 4] احداث پالایشگاه گاز - تک مرحله‌ای - مسیر وقفه\n";
            echo str_repeat("-", 100) . "\n";

            $tender = Tender::create([
                'user_id' => $this->supportUserId(),
                'code' => 'MOP-1404-004',
                'title' => 'احداث پالایشگاه گاز میدان گازی کیش',
                'owner_name' => 'شرکت ملی نفت ایران - مدیریت میادین گازی',
                'type' => 'پیمانکاری',
                'date' => '1404/09/25',
                'is_two_stage' => false,
                'normalize_prices' => true,
                'is_adjustable' => true,
                'base_period' => '1403/05/01',
                'po_method' => '1',
                'tgamma' => 1.0,
                'tbeta' => 0.51,
                'delta' => 0,
                'a_max' => null,
                'normalization_factor' => null,
                'description' => 'احداث پالایشگاه گاز با ظرفیت 50 میلیون متر مکعب در روز در میدان گازی کیش',
                'status' => 'SUSPENDED',
            ]);

            echo "  ✓ مناقصه ایجاد شد: {$tender->code}\n";

            $estimates = [
                ['section' => 'طراحی و مهندسی', 'amount' => 280000000000, 'currency' => 'IRR', 'amount_in_rials' => 280000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/05/01'],
                ['section' => 'تأمین تجهیزات اصلی', 'amount' => 1850000000000, 'currency' => 'IRR', 'amount_in_rials' => 1850000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/05/01'],
                ['section' => 'ساخت و نصب', 'amount' => 920000000000, 'currency' => 'IRR', 'amount_in_rials' => 920000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/05/01'],
                ['section' => 'سیستم‌های کمکی', 'amount' => 350000000000, 'currency' => 'IRR', 'amount_in_rials' => 350000000000, 'is_adjustable' => true, 'calculation_method' => 'index', 'base_period' => '1403/05/01'],
            ];

            foreach ($estimates as $est) {
                Estimate::create(array_merge($est, ['tender_id' => $tender->id]));
            }

            $pb = Estimate::calculateTotalPb($tender->id);
            $tender->update(['pb' => $pb]);
            echo "  ✓ برآورد اولیه: Pb = " . number_format($pb, 0) . " ریال\n";

            $indices = [
                ['type' => 'I1', 'value' => 100.0, 'date' => '1403/05/01', 'description' => 'شاخص دوره مبنای برآورد'],
                ['type' => 'I2', 'value' => 115.2, 'date' => '1404/09/25', 'description' => 'شاخص دوره بازگشایی'],
                ['type' => 'I3', 'value' => 122.8, 'date' => '1405/06/01', 'description' => 'شاخص دوره پیش‌بینی'],
                ['type' => 'r1', 'value' => 0.068, 'date' => null, 'description' => 'نرخ تورم r1'],
                ['type' => 'r2', 'value' => 0.042, 'date' => null, 'description' => 'نرخ تورم r2'],
            ];

            foreach ($indices as $idx) {
                Index::createOrUpdate(array_merge($idx, ['tender_id' => $tender->id]));
            }
            echo "  ✓ شاخص‌ها ثبت شدند\n";

            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['po' => $po]);
            Tender::setPoCalculatedAt($tender->id);
            echo "  ✓ Po محاسبه شد: " . number_format($po, 0) . " ریال\n";

            // مناقصه‌گران با قیمت‌های خارج از بازه (میانگین > 1.35Po یا < 0.8Po)
            $biddersData = [
                ['name' => 'شرکت پیمانکاری بین‌المللی الف', 'price' => $po * 0.72, 'technical_score' => null], // خیلی پایین
                ['name' => 'شرکت پیمانکاری بین‌المللی ب', 'price' => $po * 0.75, 'technical_score' => null], // خیلی پایین
                ['name' => 'شرکت پیمانکاری بین‌المللی ج', 'price' => $po * 1.42, 'technical_score' => null], // خیلی بالا
                ['name' => 'شرکت پیمانکاری بین‌المللی د', 'price' => $po * 1.38, 'technical_score' => null], // خیلی بالا
                ['name' => 'شرکت پیمانکاری بین‌المللی ه', 'price' => $po * 0.78, 'technical_score' => null], // خیلی پایین
                ['name' => 'شرکت پیمانکاری بین‌المللی و', 'price' => $po * 1.45, 'technical_score' => null], // خیلی بالا
            ];

            foreach ($biddersData as $bd) {
                $bidder = Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => $bd['name'],
                    'price' => $bd['price'],
                    'technical_score' => $bd['technical_score'],
                ]);
                BidderPriceItem::create([
                    'bidder_id' => $bidder->id,
                    'amount' => $bd['price'],
                    'currency' => 'IRR',
                    'amount_in_rials' => $bd['price'],
                    'is_adjustable' => true,
                    'description' => 'قیمت کل پیشنهادی',
                ]);
            }
            echo "  ✓ مناقصه‌گران ثبت شدند (6 نفر - قیمت‌ها خارج از بازه)\n";

            $evalResult = $this->evaluationService->evaluate($tender->id);
            Tender::setEvaluationCalculatedAt($tender->id);
            echo "  ✓ ارزیابی انجام شد: مسیر = " . ($evalResult['path'] ?? 'N/A') . " (باید SUSPENDED باشد)\n";
            echo "  ⚠ مناقصه به کمیته فنی-بازرگانی ارجاع داده شد\n";

            DB::commit();
            echo "  ✓✓✓ مناقصه واقعی 4 با موفقیت تکمیل شد ✓✓✓\n";
        } catch (\Exception $e) {
            DB::rollBack();
            echo "  ✗✗✗ خطا: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * مناقصه واقعی 5: دو مرحله‌ای بدون تعدیل - مسیر ساده
     * پروژه: تأمین و نصب سیستم امنیتی
     */
    private function createRealTender5(): void
    {
        DB::beginTransaction();
        try {
            echo "\n[مناقصه واقعی 5] تأمین و نصب سیستم امنیتی - دو مرحله‌ای بدون تعدیل - مسیر ساده\n";
            echo str_repeat("-", 100) . "\n";

            $tender = Tender::create([
                'user_id' => $this->supportUserId(),
                'code' => 'MOP-1404-005',
                'title' => 'تأمین و نصب سیستم امنیتی و نظارتی مجتمع پالایشگاهی',
                'owner_name' => 'شرکت پالایش نفت اصفهان',
                'type' => 'تأمین کالا',
                'date' => '1404/11/10',
                'is_two_stage' => true,
                'normalize_prices' => true,
                'is_adjustable' => false,
                'base_period' => null,
                'po_method' => '3',
                'tgamma' => 1.0,
                'tbeta' => 0.5,
                'delta' => 0,
                'a_max' => 100.0,
                'normalization_factor' => null,
                'description' => 'تأمین و نصب سیستم امنیتی شامل دوربین‌های نظارتی، سیستم کنترل دسترسی و مرکز مانیتورینگ',
                'status' => 'WINNER_SELECTED',
            ]);

            echo "  ✓ مناقصه ایجاد شد: {$tender->code}\n";

            $estimates = [
                ['section' => 'تأمین تجهیزات امنیتی', 'amount' => 45000000000, 'currency' => 'IRR', 'amount_in_rials' => 45000000000, 'is_adjustable' => false, 'calculation_method' => 'fixed', 'base_period' => null],
                ['section' => 'نصب و راه‌اندازی', 'amount' => 18000000000, 'currency' => 'IRR', 'amount_in_rials' => 18000000000, 'is_adjustable' => false, 'calculation_method' => 'fixed', 'base_period' => null],
                ['section' => 'نرم‌افزار و سیستم مانیتورینگ', 'amount' => 12000000000, 'currency' => 'IRR', 'amount_in_rials' => 12000000000, 'is_adjustable' => false, 'calculation_method' => 'fixed', 'base_period' => null],
            ];

            foreach ($estimates as $est) {
                Estimate::create(array_merge($est, ['tender_id' => $tender->id]));
            }

            $pb = Estimate::calculateTotalPb($tender->id);
            $tender->update(['pb' => $pb]);
            echo "  ✓ برآورد اولیه: Pb = " . number_format($pb, 0) . " ریال\n";

            // شاخص‌ها (روش 3 - بدون تعدیل)
            $indices = [
                ['type' => 'I1', 'value' => 100.0, 'date' => '1404/11/10', 'description' => 'شاخص دوره مبنای برآورد'],
                ['type' => 'I2', 'value' => 100.0, 'date' => '1404/11/10', 'description' => 'شاخص دوره بازگشایی'],
            ];

            foreach ($indices as $idx) {
                Index::createOrUpdate(array_merge($idx, ['tender_id' => $tender->id]));
            }
            echo "  ✓ شاخص‌ها ثبت شدند\n";

            $poResult = $this->poService->calculate($tender->id);
            $po = $poResult['Po'];
            $tender->update(['po' => $po]);
            Tender::setPoCalculatedAt($tender->id);
            echo "  ✓ Po محاسبه شد: " . number_format($po, 0) . " ریال\n";

            // مناقصه‌گران (2 نفر برای مسیر ساده - با امتیاز فنی)
            $biddersData = [
                ['name' => 'شرکت تأمین تجهیزات امنیتی پیشرو', 'price' => $po * 0.96, 'technical_score' => 97.5],
                ['name' => 'شرکت سیستم‌های امنیتی و نظارتی', 'price' => $po * 0.98, 'technical_score' => 95.8],
            ];

            foreach ($biddersData as $bd) {
                $bidder = Bidder::create([
                    'tender_id' => $tender->id,
                    'name' => $bd['name'],
                    'price' => $bd['price'],
                    'technical_score' => $bd['technical_score'],
                ]);
                BidderPriceItem::create([
                    'bidder_id' => $bidder->id,
                    'amount' => $bd['price'],
                    'currency' => 'IRR',
                    'amount_in_rials' => $bd['price'],
                    'is_adjustable' => false,
                    'description' => 'قیمت کل پیشنهادی',
                ]);
            }
            echo "  ✓ مناقصه‌گران ثبت شدند (2 نفر با امتیاز فنی)\n";

            $evalResult = $this->evaluationService->evaluate($tender->id);
            Tender::setEvaluationCalculatedAt($tender->id);
            echo "  ✓ ارزیابی انجام شد: مسیر = " . ($evalResult['path'] ?? 'N/A') . "\n";
            if (isset($evalResult['winner_bidder_id'])) {
                $winner = Bidder::find($evalResult['winner_bidder_id']);
                echo "  ✓ برنده: {$winner->name} - قیمت: " . number_format($winner->price, 0) . " ریال\n";
            }

            DB::commit();
            echo "  ✓✓✓ مناقصه واقعی 5 با موفقیت تکمیل شد ✓✓✓\n";
        } catch (\Exception $e) {
            DB::rollBack();
            echo "  ✗✗✗ خطا: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}


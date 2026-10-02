<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Bidder;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\EvaluationResult;
use App\Services\AlertNotificationService;
use App\Services\AuditTrailService;
use App\Services\ComplianceCheckerService;
use Illuminate\Support\Facades\Log;

/**
 * سرویس تولید گزارش هوشمند متنی
 * 
 * این سرویس گزارش کامل و قابل ویرایش از محاسبات و ارزیابی تولید می‌کند
 */
class IntelligentReportService
{
    private $auditTrailService;
    private $alertService;

    public function __construct()
    {
        $this->auditTrailService = new AuditTrailService();
        $this->alertService = new AlertNotificationService();
    }

    /**
     * تولید گزارش کامل متنی
     * 
     * @param string $tenderId
     * @param string $format فرمت خروجی: 'markdown', 'html', 'text'
     * @return string
     */
    public function generateFullReport(string $tenderId, string $format = 'markdown'): string
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            throw new \Exception('مناقصه یافت نشد');
        }

        $report = [];

        // سربرگ گزارش
        $report[] = $this->generateHeader($tender, $format);

        // بخش 1: اطلاعات کلی مناقصه
        $report[] = $this->generateTenderInfo($tender, $format);

        // بخش 2: برآورد اولیه (Pb)
        $report[] = $this->generatePbSection($tenderId, $format);

        // بخش 3: شاخص‌ها
        $report[] = $this->generateIndicesSection($tenderId, $format);

        // بخش 4: برآورد به‌هنگام (Po)
        $report[] = $this->generatePoSection($tenderId, $tender, $format);

        // بخش 5: پیشنهادات (Pi)
        $report[] = $this->generatePiSection($tenderId, $format);

        // بخش 6: ارزیابی و محاسبات
        $report[] = $this->generateEvaluationSection($tenderId, $tender, $format);

        // بخش 7: نتایج نهایی
        $report[] = $this->generateFinalResultsSection($tenderId, $format);

        // بخش 8: پیام‌ها و هشدارها
        $report[] = $this->generateMessagesSection($tenderId, $format);

        // بخش 9: خلاصه و توصیه‌ها
        $report[] = $this->generateSummarySection($tenderId, $format);

        // پاورقی
        $report[] = $this->generateFooter($format);

        $fullReport = implode("\n\n", array_filter($report));

        // تبدیل به فرمت مورد نظر
        return $this->convertFormat($fullReport, $format);
    }

    /**
     * تولید سربرگ گزارش
     */
    private function generateHeader(Tender $tender, string $format): string
    {
        $date = now()->format('Y/m/d H:i');
        
        if ($format === 'markdown') {
            return <<<MD
# گزارش ارزیابی مالی مناقصه

**کد مناقصه:** {$tender->code}  
**عنوان:** {$tender->title}  
**تاریخ تولید گزارش:** {$date}

---
MD;
        } elseif ($format === 'html') {
            return <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <title>گزارش ارزیابی مالی مناقصه</title>
    <style>
        body { font-family: 'Tahoma', 'Arial', sans-serif; direction: rtl; padding: 20px; }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin-top: 30px; border-right: 4px solid #3498db; padding-right: 10px; }
        h3 { color: #555; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
        th { background-color: #3498db; color: white; }
        .highlight { background-color: #fff3cd; padding: 10px; border-right: 4px solid #ffc107; margin: 10px 0; }
        .error { background-color: #f8d7da; padding: 10px; border-right: 4px solid #dc3545; margin: 10px 0; }
        .success { background-color: #d4edda; padding: 10px; border-right: 4px solid #28a745; margin: 10px 0; }
        code { background-color: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>گزارش ارزیابی مالی مناقصه</h1>
    <p><strong>کد مناقصه:</strong> {$tender->code}</p>
    <p><strong>عنوان:</strong> {$tender->title}</p>
    <p><strong>تاریخ تولید گزارش:</strong> {$date}</p>
    <hr>
HTML;
        } else {
            return <<<TEXT
================================================================================
گزارش ارزیابی مالی مناقصه
================================================================================
کد مناقصه: {$tender->code}
عنوان: {$tender->title}
تاریخ تولید گزارش: {$date}
================================================================================

TEXT;
        }
    }

    /**
     * تولید بخش اطلاعات کلی مناقصه
     */
    private function generateTenderInfo(Tender $tender, string $format): string
    {
        $isTwoStage = $tender->is_two_stage ? 'بله' : 'خیر';
        $isAdjustable = $tender->is_adjustable ? 'بله' : 'خیر';
        $normalizePrices = $tender->normalize_prices ? 'بله' : 'خیر';
        $status = $this->translateStatus($tender->status);

        if ($format === 'markdown') {
            return <<<MD
## 1. اطلاعات کلی مناقصه

| مشخصه | مقدار |
|-------|-------|
| کد مناقصه | {$tender->code} |
| عنوان | {$tender->title} |
| نوع | {$tender->type} |
| تاریخ | {$tender->date} |
| وضعیت | {$status} |
| مناقصه دو مرحله‌ای | {$isTwoStage} |
| قابل تعدیل | {$isAdjustable} |
| نرمال‌سازی قیمت‌ها | {$normalizePrices} |
| روش محاسبه Po | {$tender->po_method} |
MD;
        } elseif ($format === 'html') {
            return <<<HTML
    <h2>1. اطلاعات کلی مناقصه</h2>
    <table>
        <tr><th>مشخصه</th><th>مقدار</th></tr>
        <tr><td>کد مناقصه</td><td>{$tender->code}</td></tr>
        <tr><td>عنوان</td><td>{$tender->title}</td></tr>
        <tr><td>نوع</td><td>{$tender->type}</td></tr>
        <tr><td>تاریخ</td><td>{$tender->date}</td></tr>
        <tr><td>وضعیت</td><td>{$status}</td></tr>
        <tr><td>مناقصه دو مرحله‌ای</td><td>{$isTwoStage}</td></tr>
        <tr><td>قابل تعدیل</td><td>{$isAdjustable}</td></tr>
        <tr><td>نرمال‌سازی قیمت‌ها</td><td>{$normalizePrices}</td></tr>
        <tr><td>روش محاسبه Po</td><td>{$tender->po_method}</td></tr>
    </table>
HTML;
        } else {
            return <<<TEXT
1. اطلاعات کلی مناقصه
================================================================================
کد مناقصه: {$tender->code}
عنوان: {$tender->title}
نوع: {$tender->type}
تاریخ: {$tender->date}
وضعیت: {$status}
مناقصه دو مرحله‌ای: {$isTwoStage}
قابل تعدیل: {$isAdjustable}
نرمال‌سازی قیمت‌ها: {$normalizePrices}
روش محاسبه Po: {$tender->po_method}

TEXT;
        }
    }

    /**
     * تولید بخش برآورد اولیه (Pb)
     */
    private function generatePbSection(string $tenderId, string $format): string
    {
        $estimates = Estimate::getByTenderId($tenderId);
        $totalPb = Estimate::calculateTotalPb($tenderId);

        if ($format === 'markdown') {
            $section = "## 2. برآورد اولیه (Pb)\n\n";
            $section .= "**مجموع برآورد اولیه:** " . number_format($totalPb, 2) . " ریال\n\n";
            
            if (!empty($estimates)) {
                $section .= "| ردیف | شرح | مبلغ | ارز | نرخ ارز | مبلغ به ریال |\n";
                $section .= "|------|-----|------|-----|----------|--------------|\n";
                
                foreach ($estimates as $index => $estimate) {
                    $row = $index + 1;
                    $section .= "| {$row} | {$estimate['section']} | " . 
                               number_format($estimate['amount'], 2) . " | {$estimate['currency']} | " .
                               number_format($estimate['exchange_rate'], 2) . " | " .
                               number_format($estimate['amount_in_rials'], 2) . " |\n";
                }
            }
            
            return $section;
        } elseif ($format === 'html') {
            $section = "<h2>2. برآورد اولیه (Pb)</h2>\n";
            $section .= "<p><strong>مجموع برآورد اولیه:</strong> " . number_format($totalPb, 2) . " ریال</p>\n";
            
            if (!empty($estimates)) {
                $section .= "<table>\n<tr><th>ردیف</th><th>شرح</th><th>مبلغ</th><th>ارز</th><th>نرخ ارز</th><th>مبلغ به ریال</th></tr>\n";
                
                foreach ($estimates as $index => $estimate) {
                    $row = $index + 1;
                    $section .= "<tr><td>{$row}</td><td>{$estimate['section']}</td><td>" .
                               number_format($estimate['amount'], 2) . "</td><td>{$estimate['currency']}</td><td>" .
                               number_format($estimate['exchange_rate'], 2) . "</td><td>" .
                               number_format($estimate['amount_in_rials'], 2) . "</td></tr>\n";
                }
                
                $section .= "</table>\n";
            }
            
            return $section;
        } else {
            $section = "2. برآورد اولیه (Pb)\n";
            $section .= "================================================================================\n";
            $section .= "مجموع برآورد اولیه: " . number_format($totalPb, 2) . " ریال\n\n";
            
            if (!empty($estimates)) {
                foreach ($estimates as $index => $estimate) {
                    $row = $index + 1;
                    $section .= "ردیف {$row}: {$estimate['section']}\n";
                    $section .= "  مبلغ: " . number_format($estimate['amount'], 2) . " {$estimate['currency']}\n";
                    $section .= "  نرخ ارز: " . number_format($estimate['exchange_rate'], 2) . "\n";
                    $section .= "  مبلغ به ریال: " . number_format($estimate['amount_in_rials'], 2) . " ریال\n\n";
                }
            }
            
            return $section;
        }
    }

    /**
     * تولید بخش شاخص‌ها
     */
    private function generateIndicesSection(string $tenderId, string $format): string
    {
        $indices = Index::getByTenderId($tenderId);
        
        if ($format === 'markdown') {
            $section = "## 3. شاخص‌ها\n\n";
            
            if (!empty($indices)) {
                $section .= "| نوع شاخص | مقدار |\n";
                $section .= "|-----------|-------|\n";
                
                foreach ($indices as $index) {
                    $section .= "| {$index['type']} | " . number_format($index['value'], 4) . " |\n";
                }
            } else {
                $section .= "*هیچ شاخصی ثبت نشده است*\n";
            }
            
            return $section;
        } elseif ($format === 'html') {
            $section = "<h2>3. شاخص‌ها</h2>\n";
            
            if (!empty($indices)) {
                $section .= "<table>\n<tr><th>نوع شاخص</th><th>مقدار</th></tr>\n";
                
                foreach ($indices as $index) {
                    $section .= "<tr><td>{$index['type']}</td><td>" . number_format($index['value'], 4) . "</td></tr>\n";
                }
                
                $section .= "</table>\n";
            } else {
                $section .= "<p><em>هیچ شاخصی ثبت نشده است</em></p>\n";
            }
            
            return $section;
        } else {
            $section = "3. شاخص‌ها\n";
            $section .= "================================================================================\n";
            
            if (!empty($indices)) {
                foreach ($indices as $index) {
                    $section .= "{$index['type']}: " . number_format($index['value'], 4) . "\n";
                }
            } else {
                $section .= "هیچ شاخصی ثبت نشده است\n";
            }
            
            return $section . "\n";
        }
    }

    /**
     * تولید بخش برآورد به‌هنگام (Po)
     */
    private function generatePoSection(string $tenderId, Tender $tender, string $format): string
    {
        $po = floatval($tender->po ?? 0);
        $pb = Estimate::calculateTotalPb($tenderId);
        
        if ($format === 'markdown') {
            $section = "## 4. برآورد به‌هنگام (Po)\n\n";
            $section .= "**برآورد اولیه (Pb):** " . number_format($pb, 2) . " ریال\n";
            $section .= "**برآورد به‌هنگام (Po):** " . number_format($po, 2) . " ریال\n";
            
            if ($po > 0 && $pb > 0) {
                $ratio = ($po / $pb) * 100;
                $section .= "**نسبت Po به Pb:** " . number_format($ratio, 2) . "%\n";
            }
            
            if ($tender->is_adjustable) {
                $section .= "\n**روش محاسبه:** روش {$tender->po_method}\n";
                if ($tender->tbeta) {
                    $section .= "**ضریب β:** " . number_format($tender->tbeta, 4) . "\n";
                }
                if ($tender->tgamma) {
                    $section .= "**ضریب γ:** " . number_format($tender->tgamma, 4) . "\n";
                }
            }
            
            return $section;
        } elseif ($format === 'html') {
            $section = "<h2>4. برآورد به‌هنگام (Po)</h2>\n";
            $section .= "<p><strong>برآورد اولیه (Pb):</strong> " . number_format($pb, 2) . " ریال</p>\n";
            $section .= "<p><strong>برآورد به‌هنگام (Po):</strong> " . number_format($po, 2) . " ریال</p>\n";
            
            if ($po > 0 && $pb > 0) {
                $ratio = ($po / $pb) * 100;
                $section .= "<p><strong>نسبت Po به Pb:</strong> " . number_format($ratio, 2) . "%</p>\n";
            }
            
            if ($tender->is_adjustable) {
                $section .= "<p><strong>روش محاسبه:</strong> روش {$tender->po_method}</p>\n";
                if ($tender->tbeta) {
                    $section .= "<p><strong>ضریب β:</strong> " . number_format($tender->tbeta, 4) . "</p>\n";
                }
                if ($tender->tgamma) {
                    $section .= "<p><strong>ضریب γ:</strong> " . number_format($tender->tgamma, 4) . "</p>\n";
                }
            }
            
            return $section;
        } else {
            $section = "4. برآورد به‌هنگام (Po)\n";
            $section .= "================================================================================\n";
            $section .= "برآورد اولیه (Pb): " . number_format($pb, 2) . " ریال\n";
            $section .= "برآورد به‌هنگام (Po): " . number_format($po, 2) . " ریال\n";
            
            if ($po > 0 && $pb > 0) {
                $ratio = ($po / $pb) * 100;
                $section .= "نسبت Po به Pb: " . number_format($ratio, 2) . "%\n";
            }
            
            if ($tender->is_adjustable) {
                $section .= "\nروش محاسبه: روش {$tender->po_method}\n";
                if ($tender->tbeta) {
                    $section .= "ضریب β: " . number_format($tender->tbeta, 4) . "\n";
                }
                if ($tender->tgamma) {
                    $section .= "ضریب γ: " . number_format($tender->tgamma, 4) . "\n";
                }
            }
            
            return $section . "\n";
        }
    }

    /**
     * تولید بخش پیشنهادات (Pi)
     */
    private function generatePiSection(string $tenderId, string $format): string
    {
        $bidders = Bidder::getByTenderId($tenderId);
        
        if ($format === 'markdown') {
            $section = "## 5. پیشنهادات مناقصه‌گران (Pi)\n\n";
            $section .= "**تعداد مناقصه‌گران:** " . count($bidders) . "\n\n";
            
            if (!empty($bidders)) {
                $section .= "| ردیف | نام مناقصه‌گر | قیمت پیشنهادی (ریال) | امتیاز فنی |\n";
                $section .= "|------|----------------|------------------------|------------|\n";
                
                foreach ($bidders as $index => $bidder) {
                    $row = $index + 1;
                    $technicalScore = $bidder['technical_score'] ?? '-';
                    $section .= "| {$row} | {$bidder['name']} | " . 
                               number_format($bidder['price'], 2) . " | " .
                               ($technicalScore !== '-' ? number_format($technicalScore, 2) : '-') . " |\n";
                }
            }
            
            return $section;
        } elseif ($format === 'html') {
            $section = "<h2>5. پیشنهادات مناقصه‌گران (Pi)</h2>\n";
            $section .= "<p><strong>تعداد مناقصه‌گران:</strong> " . count($bidders) . "</p>\n";
            
            if (!empty($bidders)) {
                $section .= "<table>\n<tr><th>ردیف</th><th>نام مناقصه‌گر</th><th>قیمت پیشنهادی (ریال)</th><th>امتیاز فنی</th></tr>\n";
                
                foreach ($bidders as $index => $bidder) {
                    $row = $index + 1;
                    $technicalScore = $bidder['technical_score'] ?? '-';
                    $section .= "<tr><td>{$row}</td><td>{$bidder['name']}</td><td>" .
                               number_format($bidder['price'], 2) . "</td><td>" .
                               ($technicalScore !== '-' ? number_format($technicalScore, 2) : '-') . "</td></tr>\n";
                }
                
                $section .= "</table>\n";
            }
            
            return $section;
        } else {
            $section = "5. پیشنهادات مناقصه‌گران (Pi)\n";
            $section .= "================================================================================\n";
            $section .= "تعداد مناقصه‌گران: " . count($bidders) . "\n\n";
            
            if (!empty($bidders)) {
                foreach ($bidders as $index => $bidder) {
                    $row = $index + 1;
                    $technicalScore = $bidder['technical_score'] ?? '-';
                    $section .= "ردیف {$row}: {$bidder['name']}\n";
                    $section .= "  قیمت پیشنهادی: " . number_format($bidder['price'], 2) . " ریال\n";
                    if ($technicalScore !== '-') {
                        $section .= "  امتیاز فنی: " . number_format($technicalScore, 2) . "\n";
                    }
                    $section .= "\n";
                }
            }
            
            return $section;
        }
    }

    /**
     * تولید بخش ارزیابی و محاسبات
     */
    private function generateEvaluationSection(string $tenderId, Tender $tender, string $format): string
    {
        $evaluationService = new EvaluationService();
        
        try {
            $evaluationResult = $evaluationService->getResults($tenderId);
            
            // دریافت لاگ محاسبات از Audit Trail
            $history = $this->auditTrailService->getTenderHistory($tenderId);
            $calculationEntries = array_filter($history['audit_trails'], function ($entry) {
                return ($entry['action_type'] ?? '') === 'CALCULATION';
            });
            
            $calculationLog = [];
            foreach ($calculationEntries as $entry) {
                $description = $entry['description'] ?? '';
                $calculatedValues = $entry['calculated_values'] ?? [];
                if ($description) {
                    $calculationLog[] = $description;
                }
                foreach ($calculatedValues as $key => $value) {
                    if (is_numeric($value)) {
                        $calculationLog[] = "  {$key}: " . number_format($value, 2);
                    }
                }
            }
            
            if ($format === 'markdown') {
                $section = "## 6. ارزیابی و محاسبات\n\n";
                $section .= "**مسیر انتخاب شده:** {$evaluationResult['path']}\n";
                $section .= "**میانگین:** " . number_format($evaluationResult['mean'], 2) . " ریال\n";
                $section .= "**انحراف معیار:** " . number_format($evaluationResult['std_dev'], 2) . " ریال\n\n";
                
                $section .= "### جزئیات محاسبات\n\n";
                $section .= "```\n" . implode("\n", $calculationLog) . "\n```\n";
                
                return $section;
            } elseif ($format === 'html') {
                $section = "<h2>6. ارزیابی و محاسبات</h2>\n";
                $section .= "<p><strong>مسیر انتخاب شده:</strong> {$evaluationResult['path']}</p>\n";
                $section .= "<p><strong>میانگین:</strong> " . number_format($evaluationResult['mean'], 2) . " ریال</p>\n";
                $section .= "<p><strong>انحراف معیار:</strong> " . number_format($evaluationResult['std_dev'], 2) . " ریال</p>\n";
                
                $section .= "<h3>جزئیات محاسبات</h3>\n";
                $section .= "<pre><code>" . htmlspecialchars(implode("\n", $calculationLog)) . "</code></pre>\n";
                
                return $section;
            } else {
                $section = "6. ارزیابی و محاسبات\n";
                $section .= "================================================================================\n";
                $section .= "مسیر انتخاب شده: {$evaluationResult['path']}\n";
                $section .= "میانگین: " . number_format($evaluationResult['mean'], 2) . " ریال\n";
                $section .= "انحراف معیار: " . number_format($evaluationResult['std_dev'], 2) . " ریال\n\n";
                $section .= "جزئیات محاسبات:\n";
                $section .= implode("\n", $calculationLog) . "\n";
                
                return $section;
            }
        } catch (\Exception $e) {
            $errorMsg = "ارزیابی انجام نشده است: " . $e->getMessage();
            
            if ($format === 'markdown') {
                return "## 6. ارزیابی و محاسبات\n\n*{$errorMsg}*\n";
            } elseif ($format === 'html') {
                return "<h2>6. ارزیابی و محاسبات</h2>\n<p><em>{$errorMsg}</em></p>\n";
            } else {
                return "6. ارزیابی و محاسبات\n================================================================================\n{$errorMsg}\n\n";
            }
        }
    }

    /**
     * تولید بخش نتایج نهایی
     */
    private function generateFinalResultsSection(string $tenderId, string $format): string
    {
        $results = EvaluationResult::getByTenderId($tenderId);
        
        if ($format === 'markdown') {
            $section = "## 7. نتایج نهایی ارزیابی\n\n";
            
            if (!empty($results)) {
                $section .= "| ردیف | نام مناقصه‌گر | قیمت اصلی | قیمت تراز شده | قیمت نرمال شده | رتبه | برنده |\n";
                $section .= "|------|----------------|-----------|---------------|----------------|------|-------|\n";
                
                foreach ($results as $index => $result) {
                    $row = $index + 1;
                    $isWinner = ($result['is_winner_first'] ?? false) ? '✓' : '';
                    $originalPrice = $result['bidder_price'] ?? $result['original_price'] ?? 0;
                    $adjustedPrice = $result['adjusted_price'] ?? $originalPrice;
                    $section .= "| {$row} | {$result['bidder_name']} | " .
                               number_format($originalPrice, 2) . " | " .
                               number_format($adjustedPrice, 2) . " | " .
                               number_format($result['normalized_price'] ?? 0, 4) . " | " .
                               ($result['rank'] ?? '-') . " | {$isWinner} |\n";
                }
            } else {
                $section .= "*هیچ نتیجه‌ای ثبت نشده است*\n";
            }
            
            return $section;
        } elseif ($format === 'html') {
            $section = "<h2>7. نتایج نهایی ارزیابی</h2>\n";
            
            if (!empty($results)) {
                $section .= "<table>\n<tr><th>ردیف</th><th>نام مناقصه‌گر</th><th>قیمت اصلی</th><th>قیمت تراز شده</th><th>قیمت نرمال شده</th><th>رتبه</th><th>برنده</th></tr>\n";
                
                foreach ($results as $index => $result) {
                    $row = $index + 1;
                    $isWinner = ($result['is_winner_first'] ?? false) ? '✓' : '';
                    $originalPrice = $result['bidder_price'] ?? $result['original_price'] ?? 0;
                    $adjustedPrice = $result['adjusted_price'] ?? $originalPrice;
                    $section .= "<tr><td>{$row}</td><td>{$result['bidder_name']}</td><td>" .
                               number_format($originalPrice, 2) . "</td><td>" .
                               number_format($adjustedPrice, 2) . "</td><td>" .
                               number_format($result['normalized_price'] ?? 0, 4) . "</td><td>" .
                               ($result['rank'] ?? '-') . "</td><td>{$isWinner}</td></tr>\n";
                }
                
                $section .= "</table>\n";
            } else {
                $section .= "<p><em>هیچ نتیجه‌ای ثبت نشده است</em></p>\n";
            }
            
            return $section;
        } else {
            $section = "7. نتایج نهایی ارزیابی\n";
            $section .= "================================================================================\n";
            
            if (!empty($results)) {
                foreach ($results as $index => $result) {
                    $row = $index + 1;
                    $isWinner = ($result['is_winner_first'] ?? false) ? ' (برنده)' : '';
                    $originalPrice = $result['bidder_price'] ?? $result['original_price'] ?? 0;
                    $adjustedPrice = $result['adjusted_price'] ?? $originalPrice;
                    $section .= "ردیف {$row}: {$result['bidder_name']}{$isWinner}\n";
                    $section .= "  قیمت اصلی: " . number_format($originalPrice, 2) . " ریال\n";
                    $section .= "  قیمت تراز شده: " . number_format($adjustedPrice, 2) . " ریال\n";
                    $section .= "  قیمت نرمال شده: " . number_format($result['normalized_price'] ?? 0, 4) . "\n";
                    $section .= "  رتبه: " . ($result['rank'] ?? '-') . "\n\n";
                }
            } else {
                $section .= "هیچ نتیجه‌ای ثبت نشده است\n";
            }
            
            return $section;
        }
    }

    /**
     * تولید بخش پیام‌ها و هشدارها
     */
    private function generateMessagesSection(string $tenderId, string $format): string
    {
        $notifications = \App\Models\Notification::where('tender_id', $tenderId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
        
        $errors = array_filter($notifications, fn($n) => ($n['type'] ?? '') === 'ERROR');
        $warnings = array_filter($notifications, fn($n) => ($n['type'] ?? '') === 'WARNING');
        $info = array_filter($notifications, fn($n) => ($n['type'] ?? '') === 'INFO');
        
        if ($format === 'markdown') {
            $section = "## 8. پیام‌ها و هشدارها\n\n";
            $section .= "**خطاها:** " . count($errors) . " | **هشدارها:** " . count($warnings) . " | **اطلاع‌ها:** " . count($info) . "\n\n";
            
            if (!empty($errors)) {
                $section .= "### خطاها\n\n";
                foreach ($errors as $error) {
                    $section .= "- **{$error['message_id']}:** {$error['message']}\n";
                }
                $section .= "\n";
            }
            
            if (!empty($warnings)) {
                $section .= "### هشدارها\n\n";
                foreach ($warnings as $warning) {
                    $section .= "- **{$warning['message_id']}:** {$warning['message']}\n";
                }
                $section .= "\n";
            }
            
            return $section;
        } elseif ($format === 'html') {
            $section = "<h2>8. پیام‌ها و هشدارها</h2>\n";
            $section .= "<p><strong>خطاها:</strong> " . count($errors) . " | <strong>هشدارها:</strong> " . count($warnings) . " | <strong>اطلاع‌ها:</strong> " . count($info) . "</p>\n";
            
            if (!empty($errors)) {
                $section .= "<h3>خطاها</h3>\n<div class='error'>\n";
                foreach ($errors as $error) {
                    $section .= "<p><strong>{$error['message_id']}:</strong> {$error['message']}</p>\n";
                }
                $section .= "</div>\n";
            }
            
            if (!empty($warnings)) {
                $section .= "<h3>هشدارها</h3>\n<div class='highlight'>\n";
                foreach ($warnings as $warning) {
                    $section .= "<p><strong>{$warning['message_id']}:</strong> {$warning['message']}</p>\n";
                }
                $section .= "</div>\n";
            }
            
            return $section;
        } else {
            $section = "8. پیام‌ها و هشدارها\n";
            $section .= "================================================================================\n";
            $section .= "خطاها: " . count($errors) . " | هشدارها: " . count($warnings) . " | اطلاع‌ها: " . count($info) . "\n\n";
            
            if (!empty($errors)) {
                $section .= "خطاها:\n";
                foreach ($errors as $error) {
                    $section .= "  - {$error['message_id']}: {$error['message']}\n";
                }
                $section .= "\n";
            }
            
            if (!empty($warnings)) {
                $section .= "هشدارها:\n";
                foreach ($warnings as $warning) {
                    $section .= "  - {$warning['message_id']}: {$warning['message']}\n";
                }
                $section .= "\n";
            }
            
            return $section;
        }
    }

    /**
     * تولید بخش خلاصه و توصیه‌ها
     */
    private function generateSummarySection(string $tenderId, string $format): string
    {
        $tender = Tender::find($tenderId);
        $summary = $this->auditTrailService->getHistorySummary($tenderId);
        $complianceChecker = new ComplianceCheckerService();
        $compliance = $complianceChecker->checkFullCompliance($tenderId);
        
        if ($format === 'markdown') {
            $section = "## 9. خلاصه و توصیه‌ها\n\n";
            $section .= "### آمار کلی\n\n";
            $section .= "- **تعداد کل رکوردهای Audit Trail:** {$summary['total_entries']}\n";
            $section .= "- **خطاها:** {$summary['errors_count']}\n";
            $section .= "- **هشدارها:** {$summary['warnings_count']}\n";
            $section .= "- **اطلاع‌ها:** {$summary['info_count']}\n\n";
            
            $section .= "### وضعیت تطابق با دستورالعمل\n\n";
            if ($compliance['compliant']) {
                $section .= "✓ **همه محاسبات و تصمیم‌گیری‌ها مطابق دستورالعمل هستند**\n\n";
            } else {
                $section .= "✗ **مغایرت‌های زیر شناسایی شدند:**\n\n";
                foreach ($compliance['violations'] as $violation) {
                    $section .= "- {$violation}\n";
                }
                $section .= "\n";
            }
            
            return $section;
        } elseif ($format === 'html') {
            $section = "<h2>9. خلاصه و توصیه‌ها</h2>\n";
            $section .= "<h3>آمار کلی</h3>\n<ul>\n";
            $section .= "<li><strong>تعداد کل رکوردهای Audit Trail:</strong> {$summary['total_entries']}</li>\n";
            $section .= "<li><strong>خطاها:</strong> {$summary['errors_count']}</li>\n";
            $section .= "<li><strong>هشدارها:</strong> {$summary['warnings_count']}</li>\n";
            $section .= "<li><strong>اطلاع‌ها:</strong> {$summary['info_count']}</li>\n</ul>\n";
            
            $section .= "<h3>وضعیت تطابق با دستورالعمل</h3>\n";
            if ($compliance['compliant']) {
                $section .= "<div class='success'><p>✓ <strong>همه محاسبات و تصمیم‌گیری‌ها مطابق دستورالعمل هستند</strong></p></div>\n";
            } else {
                $section .= "<div class='error'><p>✗ <strong>مغایرت‌های زیر شناسایی شدند:</strong></p><ul>\n";
                foreach ($compliance['violations'] as $violation) {
                    $section .= "<li>{$violation}</li>\n";
                }
                $section .= "</ul></div>\n";
            }
            
            return $section;
        } else {
            $section = "9. خلاصه و توصیه‌ها\n";
            $section .= "================================================================================\n";
            $section .= "آمار کلی:\n";
            $section .= "  - تعداد کل رکوردهای Audit Trail: {$summary['total_entries']}\n";
            $section .= "  - خطاها: {$summary['errors_count']}\n";
            $section .= "  - هشدارها: {$summary['warnings_count']}\n";
            $section .= "  - اطلاع‌ها: {$summary['info_count']}\n\n";
            
            $section .= "وضعیت تطابق با دستورالعمل:\n";
            if ($compliance['compliant']) {
                $section .= "  ✓ همه محاسبات و تصمیم‌گیری‌ها مطابق دستورالعمل هستند\n";
            } else {
                $section .= "  ✗ مغایرت‌های زیر شناسایی شدند:\n";
                foreach ($compliance['violations'] as $violation) {
                    $section .= "    - {$violation}\n";
                }
            }
            
            return $section . "\n";
        }
    }

    /**
     * تولید پاورقی
     */
    private function generateFooter(string $format): string
    {
        $date = now()->format('Y/m/d H:i');
        
        if ($format === 'markdown') {
            return <<<MD
---

**تاریخ تولید:** {$date}  
**تولید شده توسط:** سامانه ارزیابی مالی مناقصات وزارت نفت

> این گزارش به صورت خودکار تولید شده است و قابل ویرایش می‌باشد.
MD;
        } elseif ($format === 'html') {
            return <<<HTML
    <hr>
    <footer>
        <p><strong>تاریخ تولید:</strong> {$date}</p>
        <p><strong>تولید شده توسط:</strong> سامانه ارزیابی مالی مناقصات وزارت نفت</p>
        <p><em>این گزارش به صورت خودکار تولید شده است و قابل ویرایش می‌باشد.</em></p>
    </footer>
</body>
</html>
HTML;
        } else {
            return <<<TEXT
================================================================================
تاریخ تولید: {$date}
تولید شده توسط: سامانه ارزیابی مالی مناقصات وزارت نفت

این گزارش به صورت خودکار تولید شده است و قابل ویرایش می‌باشد.
================================================================================
TEXT;
        }
    }

    /**
     * تبدیل فرمت
     */
    private function convertFormat(string $content, string $format): string
    {
        // در حال حاضر محتوا در همان فرمت تولید می‌شود
        return $content;
    }

    /**
     * ترجمه وضعیت
     */
    private function translateStatus(?string $status): string
    {
        $translations = [
            'DRAFT' => 'پیش‌نویس',
            'PB_APPROVED' => 'Pb تأیید شده',
            'BIDS_OPEN' => 'دریافت پیشنهادات',
            'BIDS_CLOSED' => 'بسته شدن پیشنهادات',
            'PO_CALCULATED' => 'Po محاسبه شده',
            'ANALYSIS_STAT' => 'تحلیل آماری',
            'WINNER_SELECTED' => 'برنده انتخاب شده',
            'SUSPENDED' => 'متوقف شده',
            'WAITING_FOR_INDICES' => 'در انتظار شاخص‌ها',
        ];

        return $translations[$status] ?? $status ?? 'نامشخص';
    }
}


<?php

namespace App\Services;

/**
 * سرویس فرمت‌بندی گزارش
 * 
 * این سرویس گزارش را در قالب‌های مختلف (PDF, Word, HTML) فرمت‌بندی می‌کند
 */
class ReportFormatterService
{
    /**
     * فرمت‌بندی گزارش به HTML
     */
    public function formatAsHtml(array $report): string
    {
        $html = $this->getHtmlHeader();
        $html .= $this->formatTenderInfo($report['tender_info']);
        $html .= $this->formatInputsSummary($report['inputs_summary']);
        $html .= $this->formatCalculations($report['calculations']);
        $html .= $this->formatDecisionPath($report['decision_path']);
        $html .= $this->formatStatisticalData($report['statistical_data']);
        $html .= $this->formatFinalResults($report['final_results']);
        $html .= $this->formatWarningsAndAlerts($report['warnings_and_alerts']);
        $html .= $this->formatManagementNotes($report['management_notes']);
        $html .= $this->formatFinalDecision($report['final_decision']);
        $html .= $this->getHtmlFooter();

        return $html;
    }

    /**
     * فرمت‌بندی گزارش به متن ساده
     */
    public function formatAsText(array $report): string
    {
        $text = "گزارش مدیریتی ارزیابی مالی مناقصه\n";
        $text .= str_repeat("=", 80) . "\n\n";

        $text .= $this->formatTenderInfoText($report['tender_info']);
        $text .= $this->formatInputsSummaryText($report['inputs_summary']);
        $text .= $this->formatCalculationsText($report['calculations']);
        $text .= $this->formatDecisionPathText($report['decision_path']);
        $text .= $this->formatStatisticalDataText($report['statistical_data']);
        $text .= $this->formatFinalResultsText($report['final_results']);
        $text .= $this->formatWarningsAndAlertsText($report['warnings_and_alerts']);
        $text .= $this->formatManagementNotesText($report['management_notes']);
        $text .= $this->formatFinalDecisionText($report['final_decision']);

        return $text;
    }

    /**
     * فرمت‌بندی گزارش به Markdown
     */
    public function formatAsMarkdown(array $report): string
    {
        $md = "# گزارش مدیریتی ارزیابی مالی مناقصه\n\n";
        $md .= $this->formatTenderInfoMarkdown($report['tender_info']);
        $md .= $this->formatInputsSummaryMarkdown($report['inputs_summary']);
        $md .= $this->formatCalculationsMarkdown($report['calculations']);
        $md .= $this->formatDecisionPathMarkdown($report['decision_path']);
        $md .= $this->formatStatisticalDataMarkdown($report['statistical_data']);
        $md .= $this->formatFinalResultsMarkdown($report['final_results']);
        $md .= $this->formatWarningsAndAlertsMarkdown($report['warnings_and_alerts']);
        $md .= $this->formatManagementNotesMarkdown($report['management_notes']);
        $md .= $this->formatFinalDecisionMarkdown($report['final_decision']);

        return $md;
    }

    // Helper methods for HTML formatting
    private function getHtmlHeader(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <title>گزارش مدیریتی ارزیابی مالی مناقصه</title>
    <style>
        body { font-family: 'Tahoma', 'Arial', sans-serif; direction: rtl; padding: 20px; line-height: 1.6; }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin-top: 30px; border-right: 4px solid #3498db; padding-right: 10px; }
        h3 { color: #555; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
        th { background-color: #3498db; color: white; }
        .warning { background-color: #fff3cd; padding: 10px; border-right: 4px solid #ffc107; margin: 10px 0; }
        .error { background-color: #f8d7da; padding: 10px; border-right: 4px solid #dc3545; margin: 10px 0; }
        .success { background-color: #d4edda; padding: 10px; border-right: 4px solid #28a745; margin: 10px 0; }
        .info { background-color: #d1ecf1; padding: 10px; border-right: 4px solid #17a2b8; margin: 10px 0; }
        .highlight { background-color: #fff3cd; font-weight: bold; }
    </style>
</head>
<body>
    <h1>گزارش مدیریتی ارزیابی مالی مناقصه</h1>
HTML;
    }

    private function getHtmlFooter(): string
    {
        $date = now()->format('Y/m/d H:i');
        return <<<HTML
    <hr>
    <footer>
        <p><strong>تاریخ تولید:</strong> {$date}</p>
        <p><strong>تولید شده توسط:</strong> سامانه ارزیابی مالی مناقصات وزارت نفت</p>
    </footer>
</body>
</html>
HTML;
    }

    private function formatTenderInfo(array $info): string
    {
        $html = "<h2>1. اطلاعات مناقصه</h2>\n";
        $html .= "<table>\n";
        $html .= "<tr><th>مشخصه</th><th>مقدار</th></tr>\n";
        $html .= "<tr><td>کد مناقصه</td><td>{$info['code']}</td></tr>\n";
        $html .= "<tr><td>عنوان</td><td>{$info['title']}</td></tr>\n";
        $html .= "<tr><td>نوع</td><td>{$info['type']}</td></tr>\n";
        $html .= "<tr><td>تاریخ</td><td>{$info['date']}</td></tr>\n";
        $html .= "<tr><td>وضعیت</td><td>{$info['status']}</td></tr>\n";
        $html .= "<tr><td>مناقصه دو مرحله‌ای</td><td>" . ($info['is_two_stage'] ? 'بله' : 'خیر') . "</td></tr>\n";
        $html .= "<tr><td>قابل تعدیل</td><td>" . ($info['is_adjustable'] ? 'بله' : 'خیر') . "</td></tr>\n";
        $html .= "</table>\n";
        return $html;
    }

    private function formatInputsSummary(array $summary): string
    {
        $html = "<h2>2. خلاصه ورودی‌ها</h2>\n";
        $html .= "<h3>2.1 برآورد اولیه (Pb)</h3>\n";
        $html .= "<p><strong>مقدار:</strong> {$summary['pb']['formatted']} ریال</p>\n";
        
        $html .= "<h3>2.2 برآورد به‌هنگام (Po)</h3>\n";
        $html .= "<p><strong>مقدار:</strong> {$summary['po']['formatted']} ریال</p>\n";

        if (!empty($summary['adjustment_indices'])) {
            $html .= "<h3>2.3 شاخص‌های تعدیل (F)</h3>\n";
            $html .= "<table>\n<tr><th>نوع</th><th>مقدار</th></tr>\n";
            foreach ($summary['adjustment_indices'] as $idx) {
                $html .= "<tr><td>{$idx['type']}</td><td>{$idx['formatted']}</td></tr>\n";
            }
            $html .= "</table>\n";
        }

        if (!empty($summary['historical_indices'])) {
            $html .= "<h3>2.4 شاخص‌های تاریخی (f)</h3>\n";
            $html .= "<table>\n<tr><th>نوع</th><th>مقدار</th></tr>\n";
            foreach ($summary['historical_indices'] as $idx) {
                $html .= "<tr><td>{$idx['type']}</td><td>{$idx['formatted']}</td></tr>\n";
            }
            $html .= "</table>\n";
        }

        $html .= "<h3>2.5 پیشنهادات مناقصه‌گران</h3>\n";
        $html .= "<p><strong>تعداد:</strong> {$summary['bidders_count']}</p>\n";
        $html .= "<table>\n<tr><th>نام</th><th>قیمت (ریال)</th><th>امتیاز فنی</th></tr>\n";
        foreach ($summary['bidders'] as $bidder) {
            $techScore = $bidder['technical_score'] ? number_format($bidder['technical_score'], 2) : '-';
            $html .= "<tr><td>{$bidder['name']}</td><td>{$bidder['formatted_price']}</td><td>{$techScore}</td></tr>\n";
        }
        $html .= "</table>\n";

        return $html;
    }

    private function formatCalculations(array $calc): string
    {
        $html = "<h2>3. محاسبات</h2>\n";
        $html .= "<p><strong>Pb:</strong> " . number_format($calc['pb'], 2) . " ریال</p>\n";
        $html .= "<p><strong>Po:</strong> " . number_format($calc['po'], 2) . " ریال</p>\n";
        if (isset($calc['po_formula'])) {
            $html .= "<p><strong>فرمول:</strong> {$calc['po_formula']}</p>\n";
        }
        if (isset($calc['mean'])) {
            $html .= "<p><strong>میانگین:</strong> " . number_format($calc['mean'], 2) . " ریال</p>\n";
        }
        if (isset($calc['std_dev'])) {
            $html .= "<p><strong>انحراف معیار:</strong> " . number_format($calc['std_dev'], 2) . " ریال</p>\n";
        }
        return $html;
    }

    private function formatDecisionPath(array $path): string
    {
        $html = "<h2>4. مسیر تصمیم‌گیری</h2>\n";
        if (!$path['path']) {
            $html .= "<p>ارزیابی انجام نشده است</p>\n";
            return $html;
        }

        $html .= "<div class='info'>\n";
        $html .= "<h3>{$path['description']}</h3>\n";
        $html .= "<p><strong>شرط:</strong> {$path['condition']}</p>\n";
        $html .= "<p><strong>اقدام:</strong> {$path['action']}</p>\n";
        $html .= "</div>\n";

        return $html;
    }

    private function formatStatisticalData(?array $data): string
    {
        if (!$data) {
            return "";
        }

        $html = "<h2>5. داده‌های آماری</h2>\n";
        $html .= "<p><strong>میانگین:</strong> " . number_format($data['mean'], 2) . " ریال</p>\n";
        $html .= "<p><strong>انحراف معیار:</strong> " . number_format($data['std_dev'], 2) . " ریال</p>\n";

        if (!empty($data['normalized_prices'])) {
            $html .= "<h3>قیمت‌های نرمال‌شده</h3>\n";
            $html .= "<table>\n<tr><th>مناقصه‌گر</th><th>قیمت نرمال شده</th><th>در دامنه اصلی</th></tr>\n";
            foreach ($data['normalized_prices'] as $np) {
                $inRange = $np['in_primary_range'] ? '✓' : '✗';
                $html .= "<tr><td>{$np['bidder_name']}</td><td>{$np['formatted']}</td><td>{$inRange}</td></tr>\n";
            }
            $html .= "</table>\n";
        }

        return $html;
    }

    private function formatFinalResults(array $results): string
    {
        $html = "<h2>6. نتایج نهایی</h2>\n";
        $html .= "<p><strong>تعداد کل مناقصه‌گران:</strong> {$results['total_bidders']}</p>\n";
        $html .= "<p><strong>مناقصه‌گران واجد شرایط:</strong> {$results['qualified_bidders']}</p>\n";

        if (!empty($results['results'])) {
            $html .= "<table>\n";
            $html .= "<tr><th>رتبه</th><th>نام</th><th>قیمت اصلی</th><th>قیمت تراز شده</th><th>قیمت نرمال شده</th><th>امتیاز نهایی</th><th>برنده</th></tr>\n";
            foreach ($results['results'] as $result) {
                $isWinner = $result['is_winner'] ? '✓' : '';
                $html .= "<tr><td>{$result['rank']}</td><td>{$result['bidder_name']}</td><td>" .
                        number_format($result['original_price'], 2) . "</td><td>" .
                        number_format($result['adjusted_price'], 2) . "</td><td>" .
                        number_format($result['normalized_price'], 4) . "</td><td>" .
                        number_format($result['final_score'], 2) . "</td><td>{$isWinner}</td></tr>\n";
            }
            $html .= "</table>\n";
        }

        if ($results['winner']) {
            $html .= "<div class='success'>\n";
            $html .= "<h3>برنده مناقصه</h3>\n";
            $html .= "<p><strong>نام:</strong> {$results['winner']['name']}</p>\n";
            $html .= "<p><strong>قیمت:</strong> " . number_format($results['winner']['price'], 2) . " ریال</p>\n";
            $html .= "<p><strong>امتیاز نهایی:</strong> " . number_format($results['winner']['final_score'], 2) . "</p>\n";
            $html .= "</div>\n";
        }

        return $html;
    }

    private function formatWarningsAndAlerts(array $alerts): string
    {
        $html = "<h2>7. هشدارها و اعلان‌ها</h2>\n";
        $html .= "<p><strong>خطاها:</strong> {$alerts['summary']['errors_count']} | " .
                "<strong>هشدارها:</strong> {$alerts['summary']['warnings_count']} | " .
                "<strong>اطلاع‌ها:</strong> {$alerts['summary']['info_count']}</p>\n";

        if (!empty($alerts['errors'])) {
            $html .= "<h3>خطاها</h3>\n";
            foreach ($alerts['errors'] as $error) {
                $html .= "<div class='error'><p><strong>{$error['message_id']}:</strong> {$error['message']}</p></div>\n";
            }
        }

        if (!empty($alerts['warnings'])) {
            $html .= "<h3>هشدارها</h3>\n";
            foreach ($alerts['warnings'] as $warning) {
                $html .= "<div class='warning'><p><strong>{$warning['message_id']}:</strong> {$warning['message']}</p></div>\n";
            }
        }

        return $html;
    }

    private function formatManagementNotes(array $notes): string
    {
        $html = "<h2>8. نکات مدیریتی</h2>\n";
        foreach ($notes as $note) {
            $class = match($note['type']) {
                'error' => 'error',
                'warning' => 'warning',
                'info' => 'info',
                default => 'info',
            };
            $html .= "<div class='{$class}'>\n";
            $html .= "<h3>{$note['title']}</h3>\n";
            $html .= "<p>{$note['description']}</p>\n";
            if (isset($note['violations'])) {
                $html .= "<ul>\n";
                foreach ($note['violations'] as $violation) {
                    $html .= "<li>{$violation}</li>\n";
                }
                $html .= "</ul>\n";
            }
            $html .= "</div>\n";
        }
        return $html;
    }

    private function formatFinalDecision(array $decision): string
    {
        $html = "<h2>9. تصمیم نهایی</h2>\n";
        $class = match($decision['status']) {
            'AWARDED' => 'success',
            'SUSPENDED' => 'warning',
            'NO_VALID_BIDS' => 'error',
            default => 'info',
        };
        $html .= "<div class='{$class}'>\n";
        $html .= "<h3>{$decision['description']}</h3>\n";
        if (isset($decision['winner'])) {
            $html .= "<p><strong>برنده:</strong> {$decision['winner']['name']}</p>\n";
            $html .= "<p><strong>قیمت:</strong> " . number_format($decision['winner']['price'], 2) . " ریال</p>\n";
        }
        $html .= "</div>\n";
        return $html;
    }

    // Text formatting methods (simplified versions)
    private function formatTenderInfoText(array $info): string
    {
        $text = "1. اطلاعات مناقصه\n";
        $text .= str_repeat("-", 80) . "\n";
        $text .= "کد: {$info['code']}\n";
        $text .= "عنوان: {$info['title']}\n";
        $text .= "نوع: {$info['type']}\n";
        $text .= "وضعیت: {$info['status']}\n\n";
        return $text;
    }

    private function formatInputsSummaryText(array $summary): string
    {
        $text = "2. خلاصه ورودی‌ها\n";
        $text .= str_repeat("-", 80) . "\n";
        $text .= "Pb: {$summary['pb']['formatted']} ریال\n";
        $text .= "Po: {$summary['po']['formatted']} ریال\n";
        $text .= "تعداد مناقصه‌گران: {$summary['bidders_count']}\n\n";
        return $text;
    }

    private function formatCalculationsText(array $calc): string
    {
        $text = "3. محاسبات\n";
        $text .= str_repeat("-", 80) . "\n";
        $text .= "Pb: " . number_format($calc['pb'], 2) . " ریال\n";
        $text .= "Po: " . number_format($calc['po'], 2) . " ریال\n";
        if (isset($calc['mean'])) {
            $text .= "میانگین: " . number_format($calc['mean'], 2) . " ریال\n";
        }
        $text .= "\n";
        return $text;
    }

    private function formatDecisionPathText(array $path): string
    {
        $text = "4. مسیر تصمیم‌گیری\n";
        $text .= str_repeat("-", 80) . "\n";
        if ($path['path']) {
            $text .= "{$path['description']}\n";
            $text .= "شرط: {$path['condition']}\n";
            $text .= "اقدام: {$path['action']}\n";
        } else {
            $text .= "ارزیابی انجام نشده است\n";
        }
        $text .= "\n";
        return $text;
    }

    private function formatStatisticalDataText(?array $data): string
    {
        if (!$data) return "";
        $text = "5. داده‌های آماری\n";
        $text .= str_repeat("-", 80) . "\n";
        $text .= "میانگین: " . number_format($data['mean'], 2) . " ریال\n";
        $text .= "انحراف معیار: " . number_format($data['std_dev'], 2) . " ریال\n\n";
        return $text;
    }

    private function formatFinalResultsText(array $results): string
    {
        $text = "6. نتایج نهایی\n";
        $text .= str_repeat("-", 80) . "\n";
        if ($results['winner']) {
            $text .= "برنده: {$results['winner']['name']}\n";
            $text .= "قیمت: " . number_format($results['winner']['price'], 2) . " ریال\n";
        } else {
            $text .= "هیچ برنده‌ای انتخاب نشد\n";
        }
        $text .= "\n";
        return $text;
    }

    private function formatWarningsAndAlertsText(array $alerts): string
    {
        $text = "7. هشدارها و اعلان‌ها\n";
        $text .= str_repeat("-", 80) . "\n";
        $text .= "خطاها: {$alerts['summary']['errors_count']}\n";
        $text .= "هشدارها: {$alerts['summary']['warnings_count']}\n\n";
        return $text;
    }

    private function formatManagementNotesText(array $notes): string
    {
        $text = "8. نکات مدیریتی\n";
        $text .= str_repeat("-", 80) . "\n";
        foreach ($notes as $note) {
            $text .= "{$note['title']}: {$note['description']}\n";
        }
        $text .= "\n";
        return $text;
    }

    private function formatFinalDecisionText(array $decision): string
    {
        $text = "9. تصمیم نهایی\n";
        $text .= str_repeat("-", 80) . "\n";
        $text .= "{$decision['description']}\n";
        if (isset($decision['winner'])) {
            $text .= "برنده: {$decision['winner']['name']}\n";
        }
        $text .= "\n";
        return $text;
    }

    // Markdown formatting methods (similar structure)
    private function formatTenderInfoMarkdown(array $info): string
    {
        return "## 1. اطلاعات مناقصه\n\n" .
               "| مشخصه | مقدار |\n|-------|-------|\n" .
               "| کد | {$info['code']} |\n" .
               "| عنوان | {$info['title']} |\n" .
               "| نوع | {$info['type']} |\n\n";
    }

    private function formatInputsSummaryMarkdown(array $summary): string
    {
        return "## 2. خلاصه ورودی‌ها\n\n" .
               "**Pb:** {$summary['pb']['formatted']} ریال\n" .
               "**Po:** {$summary['po']['formatted']} ریال\n" .
               "**تعداد مناقصه‌گران:** {$summary['bidders_count']}\n\n";
    }

    private function formatCalculationsMarkdown(array $calc): string
    {
        $md = "## 3. محاسبات\n\n";
        $md .= "**Pb:** " . number_format($calc['pb'], 2) . " ریال\n";
        $md .= "**Po:** " . number_format($calc['po'], 2) . " ریال\n";
        if (isset($calc['mean'])) {
            $md .= "**میانگین:** " . number_format($calc['mean'], 2) . " ریال\n";
        }
        return $md . "\n";
    }

    private function formatDecisionPathMarkdown(array $path): string
    {
        if (!$path['path']) {
            return "## 4. مسیر تصمیم‌گیری\n\nارزیابی انجام نشده است\n\n";
        }
        return "## 4. مسیر تصمیم‌گیری\n\n" .
               "**{$path['description']}**\n\n" .
               "شرط: {$path['condition']}\n\n" .
               "اقدام: {$path['action']}\n\n";
    }

    private function formatStatisticalDataMarkdown(?array $data): string
    {
        if (!$data) return "";
        return "## 5. داده‌های آماری\n\n" .
               "**میانگین:** " . number_format($data['mean'], 2) . " ریال\n" .
               "**انحراف معیار:** " . number_format($data['std_dev'], 2) . " ریال\n\n";
    }

    private function formatFinalResultsMarkdown(array $results): string
    {
        $md = "## 6. نتایج نهایی\n\n";
        if ($results['winner']) {
            $md .= "**برنده:** {$results['winner']['name']}\n";
            $md .= "**قیمت:** " . number_format($results['winner']['price'], 2) . " ریال\n";
        }
        return $md . "\n";
    }

    private function formatWarningsAndAlertsMarkdown(array $alerts): string
    {
        return "## 7. هشدارها و اعلان‌ها\n\n" .
               "خطاها: {$alerts['summary']['errors_count']} | " .
               "هشدارها: {$alerts['summary']['warnings_count']}\n\n";
    }

    private function formatManagementNotesMarkdown(array $notes): string
    {
        $md = "## 8. نکات مدیریتی\n\n";
        foreach ($notes as $note) {
            $md .= "### {$note['title']}\n\n{$note['description']}\n\n";
        }
        return $md;
    }

    private function formatFinalDecisionMarkdown(array $decision): string
    {
        $md = "## 9. تصمیم نهایی\n\n";
        $md .= "**{$decision['description']}**\n\n";
        if (isset($decision['winner'])) {
            $md .= "برنده: {$decision['winner']['name']}\n";
        }
        return $md . "\n";
    }
}


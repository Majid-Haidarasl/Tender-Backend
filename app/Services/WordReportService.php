<?php

namespace App\Services;

use App\Services\ReportFormatterService;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

/**
 * سرویس تولید گزارش Word (DOCX)
 */
class WordReportService
{
    private $formatter;

    public function __construct()
    {
        $this->formatter = new ReportFormatterService();
    }

    /**
     * تولید گزارش Word
     * 
     * @param array $report داده‌های گزارش
     * @return string مسیر فایل Word
     */
    public function generateWord(array $report): string
    {
        $phpWord = new PhpWord();
        
        // تنظیمات فونت فارسی
        $phpWord->setDefaultFontName('Tahoma');
        $phpWord->setDefaultFontSize(11);
        
        // ایجاد بخش
        $section = $phpWord->addSection([
            'marginTop' => 1440,
            'marginBottom' => 1440,
            'marginLeft' => 1440,
            'marginRight' => 1440,
        ]);

        // عنوان اصلی
        $section->addText('گزارش مدیریتی ارزیابی مالی مناقصه', [
            'name' => 'Tahoma',
            'size' => 18,
            'bold' => true,
        ], ['alignment' => 'center']);
        
        $section->addTextBreak(1);

        // اطلاعات مناقصه
        $this->addTenderInfo($section, $report['tender_info']);
        $section->addTextBreak(1);

        // خلاصه ورودی‌ها
        $this->addInputsSummary($section, $report['inputs_summary']);
        $section->addTextBreak(1);

        // محاسبات
        $this->addCalculations($section, $report['calculations']);
        $section->addTextBreak(1);

        // مسیر تصمیم‌گیری
        $this->addDecisionPath($section, $report['decision_path']);
        $section->addTextBreak(1);

        // داده‌های آماری
        if ($report['statistical_data']) {
            $this->addStatisticalData($section, $report['statistical_data']);
            $section->addTextBreak(1);
        }

        // نتایج نهایی
        $this->addFinalResults($section, $report['final_results']);
        $section->addTextBreak(1);

        // هشدارها و اعلان‌ها
        $this->addWarningsAndAlerts($section, $report['warnings_and_alerts']);
        $section->addTextBreak(1);

        // نکات مدیریتی
        $this->addManagementNotes($section, $report['management_notes']);
        $section->addTextBreak(1);

        // تصمیم نهایی
        $this->addFinalDecision($section, $report['final_decision']);

        // ذخیره فایل
        $filename = 'management_report_' . $report['tender_info']['code'] . '_' . now()->format('Y-m-d_H-i-s') . '.docx';
        $path = storage_path('app/reports/' . $filename);
        
        // اطمینان از وجود دایرکتوری
        if (!file_exists(storage_path('app/reports'))) {
            mkdir(storage_path('app/reports'), 0755, true);
        }
        
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($path);
        
        return $path;
    }

    private function addTenderInfo($section, array $info): void
    {
        $section->addText('1. اطلاعات مناقصه', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '000000']);
        $table->addRow();
        $table->addCell(3000)->addText('کد مناقصه', ['bold' => true]);
        $table->addCell(5000)->addText($info['code']);
        
        $table->addRow();
        $table->addCell(3000)->addText('عنوان', ['bold' => true]);
        $table->addCell(5000)->addText($info['title']);
        
        $table->addRow();
        $table->addCell(3000)->addText('نوع', ['bold' => true]);
        $table->addCell(5000)->addText($info['type']);
        
        $table->addRow();
        $table->addCell(3000)->addText('وضعیت', ['bold' => true]);
        $table->addCell(5000)->addText($info['status']);
    }

    private function addInputsSummary($section, array $summary): void
    {
        $section->addText('2. خلاصه ورودی‌ها', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        $section->addText('برآورد اولیه (Pb): ' . $summary['pb']['formatted'] . ' ریال');
        $section->addText('برآورد به‌هنگام (Po): ' . $summary['po']['formatted'] . ' ریال');
        $section->addText('تعداد مناقصه‌گران: ' . $summary['bidders_count']);
    }

    private function addCalculations($section, array $calc): void
    {
        $section->addText('3. محاسبات', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        $section->addText('Pb: ' . number_format($calc['pb'], 2) . ' ریال');
        $section->addText('Po: ' . number_format($calc['po'], 2) . ' ریال');
        if (isset($calc['po_formula'])) {
            $section->addText('فرمول: ' . $calc['po_formula']);
        }
    }

    private function addDecisionPath($section, array $path): void
    {
        $section->addText('4. مسیر تصمیم‌گیری', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        if ($path['path']) {
            $section->addText($path['description'], ['bold' => true]);
            $section->addText('شرط: ' . $path['condition']);
            $section->addText('اقدام: ' . $path['action']);
        } else {
            $section->addText('ارزیابی انجام نشده است');
        }
    }

    private function addStatisticalData($section, ?array $data): void
    {
        if (!$data) return;
        
        $section->addText('5. داده‌های آماری', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        $section->addText('میانگین: ' . number_format($data['mean'], 2) . ' ریال');
        $section->addText('انحراف معیار: ' . number_format($data['std_dev'], 2) . ' ریال');
    }

    private function addFinalResults($section, array $results): void
    {
        $section->addText('6. نتایج نهایی', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        if ($results['winner']) {
            $section->addText('برنده: ' . $results['winner']['name'], ['bold' => true]);
            $section->addText('قیمت: ' . number_format($results['winner']['price'], 2) . ' ریال');
        } else {
            $section->addText('هیچ برنده‌ای انتخاب نشد');
        }
    }

    private function addWarningsAndAlerts($section, array $alerts): void
    {
        $section->addText('7. هشدارها و اعلان‌ها', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        $section->addText('خطاها: ' . $alerts['summary']['errors_count']);
        $section->addText('هشدارها: ' . $alerts['summary']['warnings_count']);
    }

    private function addManagementNotes($section, array $notes): void
    {
        $section->addText('8. نکات مدیریتی', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        foreach ($notes as $note) {
            $section->addText($note['title'] . ': ' . $note['description']);
        }
    }

    private function addFinalDecision($section, array $decision): void
    {
        $section->addText('9. تصمیم نهایی', ['bold' => true, 'size' => 14]);
        $section->addTextBreak(1);
        
        $section->addText($decision['description'], ['bold' => true]);
        if (isset($decision['winner'])) {
            $section->addText('برنده: ' . $decision['winner']['name']);
        }
    }
}


<?php

namespace App\Services;

use App\Services\ReportFormatterService;
use Barryvdh\DomPDF\Facade\Pdf as PDFFacade;

/**
 * سرویس تولید گزارش PDF
 */
class PdfReportService
{
    private $formatter;

    public function __construct()
    {
        $this->formatter = new ReportFormatterService();
    }

    /**
     * تولید گزارش PDF
     * 
     * @param array $report داده‌های گزارش
     * @return string مسیر فایل PDF
     */
    public function generatePdf(array $report): string
    {
        // تبدیل HTML به PDF
        $html = $this->formatter->formatAsHtml($report);
        
        // استفاده از کتابخانه dompdf یا wkhtmltopdf
        // در اینجا از dompdf استفاده می‌کنیم
        try {
            $pdf = PDFFacade::loadHTML($html);
            $pdf->setPaper('a4', 'portrait');
            $pdf->setOption('encoding', 'UTF-8');
            $pdf->setOption('enable-local-file-access', true);
            
            $filename = 'management_report_' . $report['tender_info']['code'] . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
            $path = storage_path('app/reports/' . $filename);
            
            // اطمینان از وجود دایرکتوری
            if (!file_exists(storage_path('app/reports'))) {
                mkdir(storage_path('app/reports'), 0755, true);
            }
            
            $pdf->save($path);
            
            return $path;
        } catch (\Exception $e) {
            // اگر PDF library نصب نشده باشد، از روش جایگزین استفاده می‌کنیم
            throw new \Exception('خطا در تولید PDF: ' . $e->getMessage() . '. لطفاً کتابخانه dompdf را نصب کنید: composer require barryvdh/laravel-dompdf');
        }
    }

    /**
     * تولید PDF و بازگرداندن response برای دانلود
     */
    public function generatePdfResponse(array $report): \Illuminate\Http\Response
    {
        $html = $this->formatter->formatAsHtml($report);
        
        try {
            $pdf = PDFFacade::loadHTML($html);
            $pdf->setPaper('a4', 'portrait');
            $pdf->setOption('encoding', 'UTF-8');
            
            $filename = 'management_report_' . $report['tender_info']['code'] . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
            
            return $pdf->download($filename);
        } catch (\Exception $e) {
            throw new \Exception('خطا در تولید PDF: ' . $e->getMessage());
        }
    }
}


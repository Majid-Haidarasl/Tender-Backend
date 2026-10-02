<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Services\ManagementReportService;
use App\Services\ReportFormatterService;
use App\Services\PdfReportService;
use App\Services\WordReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ManagementReportController extends Controller
{
    use AuthorizesOwnedTender;

    /**
     * دریافت گزارش مدیریتی (JSON)
     */
    public function getReport(string $tenderId, Request $request): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $options = [
                'ignore_validation' => $request->query('ignore_validation', false),
            ];

            $reportService = new ManagementReportService();
            $report = $reportService->generateManagementReport($tenderId, $options);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت گزارش در فرمت HTML
     */
    public function getHtmlReport(string $tenderId, Request $request): Response
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return response("<html><body><h1>خطا</h1><p>مناقصه یافت نشد</p></body></html>")
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }

            $options = [
                'ignore_validation' => $request->query('ignore_validation', false),
            ];

            $reportService = new ManagementReportService();
            $report = $reportService->generateManagementReport($tenderId, $options);

            $formatter = new ReportFormatterService();
            $html = $formatter->formatAsHtml($report);

            return response($html)
                ->header('Content-Type', 'text/html; charset=utf-8');
        } catch (\Exception $e) {
            return response("<html><body><h1>خطا</h1><p>{$e->getMessage()}</p></body></html>")
                ->header('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * دریافت گزارش در فرمت Markdown
     */
    public function getMarkdownReport(string $tenderId, Request $request): JsonResponse|Response
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $options = [
                'ignore_validation' => $request->query('ignore_validation', false),
            ];

            $reportService = new ManagementReportService();
            $report = $reportService->generateManagementReport($tenderId, $options);

            $formatter = new ReportFormatterService();
            $markdown = $formatter->formatAsMarkdown($report);

            $download = $request->query('download', false);
            if ($download) {
                $filename = "management_report_{$tenderId}_" . now()->format('Y-m-d_H-i-s') . ".md";
                return response($markdown)
                    ->header('Content-Type', 'text/markdown; charset=utf-8')
                    ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'report' => $markdown,
                    'format' => 'markdown',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت گزارش در فرمت متنی ساده
     */
    public function getTextReport(string $tenderId, Request $request): JsonResponse|Response
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $options = [
                'ignore_validation' => $request->query('ignore_validation', false),
            ];

            $reportService = new ManagementReportService();
            $report = $reportService->generateManagementReport($tenderId, $options);

            $formatter = new ReportFormatterService();
            $text = $formatter->formatAsText($report);

            $download = $request->query('download', false);
            if ($download) {
                $filename = "management_report_{$tenderId}_" . now()->format('Y-m-d_H-i-s') . ".txt";
                return response($text)
                    ->header('Content-Type', 'text/plain; charset=utf-8')
                    ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'report' => $text,
                    'format' => 'text',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت گزارش در فرمت PDF
     */
    public function getPdfReport(string $tenderId, Request $request): Response
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return response("<html><body><h1>خطا</h1><p>مناقصه یافت نشد</p></body></html>")
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }

            $options = [
                'ignore_validation' => $request->query('ignore_validation', false),
            ];

            $reportService = new ManagementReportService();
            $report = $reportService->generateManagementReport($tenderId, $options);

            $pdfService = new PdfReportService();
            return $pdfService->generatePdfResponse($report);
        } catch (\Exception $e) {
            return response("<html><body><h1>خطا</h1><p>{$e->getMessage()}</p></body></html>")
                ->header('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * دریافت گزارش در فرمت Word (DOCX)
     */
    public function getWordReport(string $tenderId, Request $request): Response
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $options = [
                'ignore_validation' => $request->query('ignore_validation', false),
            ];

            $reportService = new ManagementReportService();
            $report = $reportService->generateManagementReport($tenderId, $options);

            $wordService = new WordReportService();
            $filePath = $wordService->generateWord($report);

            $filename = basename($filePath);
            
            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

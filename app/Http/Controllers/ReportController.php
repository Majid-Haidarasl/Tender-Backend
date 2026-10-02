<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Services\IntelligentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    use AuthorizesOwnedTender;

    /**
     * دریافت گزارش متنی
     */
    public function getTextReport(string $tenderId, Request $request): JsonResponse|Response
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $format = $request->query('format', 'markdown'); // markdown, html, text
            $download = $request->query('download', false);

            $reportService = new IntelligentReportService();
            $report = $reportService->generateFullReport($tenderId, $format);

            if ($download) {
                $filename = "report_{$tenderId}_" . now()->format('Y-m-d_H-i-s');
                $extension = match ($format) {
                    'html' => 'html',
                    'text' => 'txt',
                    default => 'md',
                };

                return response($report)
                    ->header('Content-Type', match ($format) {
                        'html' => 'text/html; charset=utf-8',
                        'text' => 'text/plain; charset=utf-8',
                        default => 'text/markdown; charset=utf-8',
                    })
                    ->header('Content-Disposition', "attachment; filename=\"{$filename}.{$extension}\"");
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'report' => $report,
                    'format' => $format,
                    'tender_id' => $tenderId,
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
     * دریافت گزارش در فرمت Markdown
     */
    public function getMarkdownReport(string $tenderId, Request $request): JsonResponse|Response
    {
        $request->merge(['format' => 'markdown']);

        return $this->getTextReport($tenderId, $request);
    }

    /**
     * دریافت گزارش در فرمت HTML
     */
    public function getHtmlReport(string $tenderId, Request $request): JsonResponse|Response
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $reportService = new IntelligentReportService();
            $report = $reportService->generateFullReport($tenderId, 'html');

            return response($report)
                ->header('Content-Type', 'text/html; charset=utf-8');
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
    public function getPlainTextReport(string $tenderId, Request $request): JsonResponse|Response
    {
        $request->merge(['format' => 'text']);

        return $this->getTextReport($tenderId, $request);
    }
}

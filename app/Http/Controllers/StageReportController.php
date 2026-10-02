<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Services\StageReportService;
use App\Services\ComplianceCheckerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StageReportController extends Controller
{
    use AuthorizesOwnedTender;

    /**
     * دریافت گزارش یک مرحله
     */
    public function getStageReport(string $tenderId, Request $request): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $stage = $request->query('stage');
            if (!$stage) {
                return response()->json([
                    'success' => false,
                    'message' => 'لطفاً مرحله را مشخص کنید',
                ], 400);
            }

            $reportService = new StageReportService();
            $report = $reportService->generateStageReport($tenderId, $stage);

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
     * دریافت گزارش جامع مناقصه
     */
    public function getComprehensiveReport(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $reportService = new StageReportService();
            $report = $reportService->generateComprehensiveReport($tenderId);

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
     * بررسی تطابق با دستورالعمل
     */
    public function checkCompliance(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $complianceChecker = new ComplianceCheckerService();
            $compliance = $complianceChecker->checkFullCompliance($tenderId);

            return response()->json([
                'success' => true,
                'data' => $compliance,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

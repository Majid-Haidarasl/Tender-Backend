<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditTrailController extends Controller
{
    use AuthorizesOwnedTender;

    private $auditTrailService;

    public function __construct()
    {
        $this->auditTrailService = new AuditTrailService();
    }

    /**
     * دریافت تاریخچه کامل یک مناقصه
     */
    public function getHistory(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $userId = auth()->id();
            $history = $this->auditTrailService->getTenderHistory($tenderId, $userId);

            return response()->json([
                'success' => true,
                'data' => $history,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت خلاصه تاریخچه
     */
    public function getSummary(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $summary = $this->auditTrailService->getHistorySummary($tenderId);

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * بازپخش سناریو
     */
    public function replayScenario(string $tenderId, Request $request): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $stage = $request->query('stage');
            $replayData = $this->auditTrailService->replayScenario($tenderId, $stage);

            return response()->json([
                'success' => true,
                'data' => $replayData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت تاریخچه ورودی‌ها
     */
    public function getInputHistory(string $tenderId, Request $request): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $inputType = $request->query('input_type');
            $history = \App\Models\InputHistory::getByTenderId($tenderId, $inputType);

            return response()->json([
                'success' => true,
                'data' => $history,
                'count' => count($history),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت تاریخچه تصمیم‌گیری
     */
    public function getDecisionHistory(string $tenderId, Request $request): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $decisionPath = $request->query('decision_path');
            $history = \App\Models\DecisionHistory::getByTenderId($tenderId, $decisionPath);

            return response()->json([
                'success' => true,
                'data' => $history,
                'count' => count($history),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

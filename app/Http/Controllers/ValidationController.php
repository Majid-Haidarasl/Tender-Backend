<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Services\ComprehensiveValidationFramework;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidationController extends Controller
{
    use AuthorizesOwnedTender;

    /**
     * اعتبارسنجی جامع یک مناقصه
     */
    public function validateTender(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $framework = new ComprehensiveValidationFramework();
            $report = $framework->getValidationReport($tenderId);

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
     * دریافت گزارش اعتبارسنجی
     */
    public function getReport(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $framework = new ComprehensiveValidationFramework();
            $report = $framework->getValidationReport($tenderId);

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
}

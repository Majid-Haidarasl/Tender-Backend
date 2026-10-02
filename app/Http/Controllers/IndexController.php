<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Models\Index;
use App\Models\Tender;
use App\Models\EvaluationResult;
use App\Services\CalculationCacheService;
use App\Services\TenderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    use AuthorizesOwnedTender;

    private function requireOwnedIndex(Request $request, string $id): Index|JsonResponse
    {
        $index = Index::find($id);

        if (!$index || !TenderAccessService::canAccess($request, $index->tender_id)) {
            return $this->ownedTenderNotFoundResponse();
        }

        return $index;
    }
    /**
     * Get all indices (filtered by tender_id query param)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tenderId = $request->query('tender_id');
            
            if ($tenderId) {
                if (!TenderAccessService::canAccess($request, $tenderId)) {
                    return $this->ownedTenderNotFoundResponse();
                }

                $indices = Index::getByTenderId($tenderId);
                return response()->json([
                    'success' => true,
                    'data' => $indices,
                    'count' => count($indices),
                ]);
            }

            // Return empty if no tender_id specified
            return response()->json([
                'success' => true,
                'data' => [],
                'count' => 0,
                'message' => 'Please specify tender_id query parameter',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all indices for a tender
     */
    public function getByTenderId(Request $request, string $tenderId): JsonResponse
    {
        try {
            if (!TenderAccessService::canAccess($request, $tenderId)) {
                return $this->ownedTenderNotFoundResponse();
            }

            $indices = Index::getByTenderId($tenderId);
            
            return response()->json([
                'success' => true,
                'data' => $indices,
                'count' => count($indices),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get index by ID
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $index = $this->requireOwnedIndex($request, $id);
            if ($index instanceof JsonResponse) {
                return $index;
            }

            return response()->json([
                'success' => true,
                'data' => $index->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get index by tender ID and type
     */
    public function getByTenderIdAndType(Request $request, string $tenderId, string $type): JsonResponse
    {
        try {
            if (!TenderAccessService::canAccess($request, $tenderId)) {
                return $this->ownedTenderNotFoundResponse();
            }

            $index = Index::getByTenderIdAndType($tenderId, $type);
            
            if (!$index) {
                return response()->json([
                    'success' => false,
                    'message' => 'شاخص یافت نشد',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $index->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create new index
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'tender_id' => 'required|string|exists:tenders,id',
                'type' => 'required|string|max:50',
                'value' => 'required|numeric|min:0',
                'date' => 'nullable|date',
                'description' => 'nullable|string|max:500',
            ], [
                'tender_id.required' => 'شناسه مناقصه الزامی است',
                'tender_id.exists' => 'مناقصه یافت نشد',
                'type.required' => 'نوع شاخص الزامی است',
                'type.max' => 'نوع شاخص نباید بیشتر از ۵۰ کاراکتر باشد',
                'value.required' => 'مقدار شاخص الزامی است',
                'value.numeric' => 'مقدار شاخص باید یک عدد معتبر باشد',
                'value.min' => 'مقدار شاخص نمی‌تواند منفی باشد',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'اطلاعات وارد شده نامعتبر است',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $data = $request->all();

            // Check if tender exists and belongs to user
            $tender = $this->requireOwnedTender($request, $data['tender_id']);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            // Set defaults
            $data['date'] = $data['date'] ?? null;
            $data['description'] = $data['description'] ?? '';

            $index = Index::createOrUpdate($data);

            // Invalidate Po and Evaluation when indices change (Evaluation depends on Po)
            Tender::invalidatePo($data['tender_id']);
            Tender::invalidateEvaluation($data['tender_id']);
            EvaluationResult::deleteByTenderId($data['tender_id']);

            return response()->json([
                'success' => true,
                'message' => 'Index created/updated successfully',
                'data' => $index->toArray(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update index
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $index = $this->requireOwnedIndex($request, $id);
            if ($index instanceof JsonResponse) {
                return $index;
            }

            $data = $request->all();
            $index->update($data);

            // باطل کردن cache محاسبات (Po و Evaluation)
            $cacheService = new CalculationCacheService();
            $cacheService->invalidatePoCache($index->tender_id);
            $cacheService->invalidateEvaluationCache($index->tender_id);
            Tender::invalidatePo($index->tender_id);
            Tender::invalidateEvaluation($index->tender_id);
            EvaluationResult::deleteByTenderId($index->tender_id);

            return response()->json([
                'success' => true,
                'message' => 'Index updated successfully',
                'data' => $index->fresh()->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete index
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $index = $this->requireOwnedIndex($request, $id);
            if ($index instanceof JsonResponse) {
                return $index;
            }

            $tenderId = $index->tender_id;
            $index->delete();

            // باطل کردن cache محاسبات (Po و Evaluation)
            $cacheService = new CalculationCacheService();
            $cacheService->invalidatePoCache($tenderId);
            $cacheService->invalidateEvaluationCache($tenderId);
            Tender::invalidatePo($tenderId);
            Tender::invalidateEvaluation($tenderId);
            EvaluationResult::deleteByTenderId($tenderId);

            return response()->json([
                'success' => true,
                'message' => 'Index deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk create/update indices
     */
    public function bulkUpsert(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            if (empty($data['tender_id']) || !isset($data['indices']) || !is_array($data['indices'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: tender_id, indices (array)',
                ], 400);
            }

            // Check if tender exists and belongs to user
            $tender = $this->requireOwnedTender($request, $data['tender_id']);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            // Add tender_id to each index
            $indicesData = array_map(function ($index) use ($data) {
                return array_merge($index, ['tender_id' => $data['tender_id']]);
            }, $data['indices']);

            Index::bulkUpsert($indicesData);

            // Invalidate Po and Evaluation when indices change (Evaluation depends on Po)
            Tender::invalidatePo($data['tender_id']);
            Tender::invalidateEvaluation($data['tender_id']);
            EvaluationResult::deleteByTenderId($data['tender_id']);

            $updatedIndices = Index::getByTenderId($data['tender_id']);

            return response()->json([
                'success' => true,
                'message' => 'Indices created/updated successfully',
                'data' => $updatedIndices,
                'count' => count($updatedIndices),
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error in IndexController::bulkUpsert', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
                'data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در ذخیره شاخص‌ها: ' . $e->getMessage()
                    : 'خطا در ذخیره شاخص‌ها',
            ], 500);
        }
    }
}


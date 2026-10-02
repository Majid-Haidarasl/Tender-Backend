<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Models\Tender;
use App\Models\Estimate;
use App\Models\EvaluationResult;
use App\Services\PoCalculationService;
use App\Services\EvaluationService;
use App\Services\TenderAccessService;
use App\Services\CalculationCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * @OA\Tag(
 *     name="Tenders",
 *     description="API endpoints for managing tenders"
 * )
 */
class TenderController extends Controller
{
    use AuthorizesOwnedTender;

    /**
     * Clear cache for a tender
     */
    private function clearTenderCache(?string $tenderId = null, ?string $userId = null): void
    {
        try {
            Cache::forget('dashboard_stats_v1');
            if ($userId) {
                Cache::forget(TenderAccessService::dashboardCacheKey($userId));
            }
            if ($tenderId) {
                Cache::forget('tender_' . $tenderId);
            }
            // Clear all list caches using pattern matching for Redis
            try {
                $store = Cache::getStore();
                if (method_exists($store, 'getRedis') && $store instanceof \Illuminate\Cache\RedisStore) {
                    $redis = $store->getRedis();
                    if (method_exists($redis, 'keys')) {
                        $keys = $redis->keys('*tenders_list_*');
                        foreach ($keys as $key) {
                            Cache::forget(str_replace(config('cache.prefix', ''), '', $key));
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to clear tender list cache', ['error' => $e->getMessage()]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to clear tender cache', ['error' => $e->getMessage()]);
        }
    }
    /**
     * Get all tenders with pagination and search
     * 
     * @OA\Get(
     *     path="/api/tenders",
     *     summary="Get list of tenders",
     *     tags={"Tenders"},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search term for code, title, owner_name, or type",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page (max 100)",
     *         required=false,
     *         @OA\Schema(type="integer", default=10, maximum=100)
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"draft", "active", "completed", "cancelled"})
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         description="Filter by type",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Tender")),
     *             @OA\Property(property="pagination", type="object")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            set_time_limit(90);

            $userId = TenderAccessService::userId($request);
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'احراز هویت لازم است',
                ], 401);
            }

            $search = substr(trim($request->query('search', '')), 0, 100);
            $page = (int) $request->query('page', 1);
            $perPage = min((int) $request->query('per_page', 10), 50);
            $filters = [
                'status' => $request->query('status'),
                'type' => $request->query('type'),
            ];

            $query = TenderAccessService::ownedQuery($request);

            // Apply search filter on indexed fields
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('title', 'like', "%{$search}%")
                      ->orWhere('type', 'like', "%{$search}%");
                });
            }

            // Apply filters
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            // Apply pagination and select only needed fields (before count)
            // بررسی وجود ستون‌های محاسبه قبل از select
            $selectFields = [
                'id', 'code', 'title', 'type', 'date',
                'is_two_stage', 'normalize_prices', 'is_adjustable',
                'base_period', 'po_method', 'status',
                'pb', 'po', 'created_at', 'updated_at'
            ];
            
            // اضافه کردن owner_name و description در صورت وجود
            if (Schema::hasColumn('tenders', 'owner_name')) {
                $selectFields[] = 'owner_name';
            }
            if (Schema::hasColumn('tenders', 'description')) {
                $selectFields[] = 'description';
            }
            
            // اضافه کردن ستون‌های محاسبه فقط در صورت وجود
            if (Schema::hasColumn('tenders', 'po_calculated_at')) {
                $selectFields[] = 'po_calculated_at';
            }
            if (Schema::hasColumn('tenders', 'evaluation_calculated_at')) {
                $selectFields[] = 'evaluation_calculated_at';
            }
            
            $query->orderBy('created_at', 'desc')
                  ->select($selectFields);
            
            // Get paginated results FIRST (قبل از count برای بهبود عملکرد)
            $tenders = $query->skip(($page - 1) * $perPage)
                             ->take($perPage)
                             ->get()
                             ->toArray();
            
            // Get total count - بهینه‌سازی شده با timeout protection
            $total = null;
            try {
                // Set a shorter timeout for count query
                set_time_limit(15); // 15 seconds for count
                
                // If search or filter exists, perform accurate count
                if (!empty($search) || !empty($filters['status']) || !empty($filters['type'])) {
                    $countQuery = DB::table('tenders')->where('user_id', $userId);
                    
                    if (!empty($search)) {
                        $countQuery->where(function ($q) use ($search) {
                            $q->where('code', 'like', "%{$search}%")
                              ->orWhere('title', 'like', "%{$search}%")
                              ->orWhere('type', 'like', "%{$search}%");
                        });
                    }
                    
                    if (!empty($filters['status'])) {
                        $countQuery->where('status', $filters['status']);
                    }
                    
                    if (!empty($filters['type'])) {
                        $countQuery->where('type', $filters['type']);
                    }
                    
                    // Use approximate count for better performance, limited to 1000
                    $total = min($countQuery->limit(1000)->count(), 1000);
                } else {
                    $total = min(DB::table('tenders')->where('user_id', $userId)->limit(1000)->count(), 1000);
                }
                
                set_time_limit(90);
            } catch (\Exception $e) {
                // If count query times out, use estimate
                Log::warning('Count query timeout, using estimate', ['error' => $e->getMessage()]);
                $total = ($page + 1) * $perPage;
                set_time_limit(90);
            }
            
            $result = [
                'success' => true,
                'data' => $tenders,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $total > 0 ? ceil($total / $perPage) : 1,
                ],
            ];
            
            
            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error fetching tenders', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت لیست مناقصات: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get tender by ID
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            // Get related data with eager loading to prevent N+1 queries
            $tenderArray = $tender->withValidityFlags()->toArray();
            $tenderArray['estimates'] = Estimate::getByTenderId($tender->id);


            return response()->json([
                'success' => true,
                'data' => $tenderArray,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching tender', [
                'tender_id' => $id,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در دریافت مناقصه: ' . $e->getMessage()
                    : 'خطا در دریافت مناقصه',
            ], 500);
        }
    }

    /**
     * Create new tender
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = TenderAccessService::user($request);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'احراز هویت لازم است',
                ], 401);
            }

            $data = $request->all();

            // Normalize boolean values for proper storage
            if (array_key_exists('is_two_stage', $data)) {
                $data['is_two_stage'] = filter_var($data['is_two_stage'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
            if (array_key_exists('normalize_prices', $data)) {
                $data['normalize_prices'] = filter_var($data['normalize_prices'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
            if (array_key_exists('is_adjustable', $data)) {
                $data['is_adjustable'] = filter_var($data['is_adjustable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }

            // Validation
            if (empty($data['code']) || empty($data['title']) || empty($data['type']) || empty($data['date'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: code, title, type, date',
                ], 400);
            }

            // Validate numeric fields if provided
            if (isset($data['tgamma']) && (!is_numeric($data['tgamma']) || floatval($data['tgamma']) < 0)) {
                return response()->json([
                    'success' => false,
                    'message' => 'tgamma must be a valid positive number',
                ], 400);
            }
            if (isset($data['tbeta']) && (!is_numeric($data['tbeta']) || floatval($data['tbeta']) < 0)) {
                return response()->json([
                    'success' => false,
                    'message' => 'tbeta must be a valid positive number',
                ], 400);
            }
            if (isset($data['delta']) && !is_numeric($data['delta'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'delta must be a valid number',
                ], 400);
            }

            // Check if code already exists for this user
            $existing = Tender::getByCode($data['code'], $user->id);
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tender code already exists',
                ], 400);
            }

            $data['user_id'] = $user->id;
            $tender = Tender::create($data);

            // Clear list cache
            $this->clearTenderCache(null, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Tender created successfully',
                'data' => $tender->toArray(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update tender
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            // SECURITY: User must be authenticated (handled by middleware, but double-check)
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'احراز هویت لازم است',
                ], 401);
            }
            
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            // SECURITY: Only allow specific fields to be updated (prevent mass assignment)
            $data = $request->only([
                'code', 'title', 'owner_name', 'type', 'date',
                'is_two_stage', 'normalize_prices', 'is_adjustable',
                'base_period', 'po_method', 'tgamma', 'tbeta', 'delta',
                'a_max', 'normalization_factor', 'description', 'status'
            ]);

            // Normalize boolean values for proper storage
            if (array_key_exists('is_two_stage', $data)) {
                $data['is_two_stage'] = filter_var($data['is_two_stage'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
            if (array_key_exists('normalize_prices', $data)) {
                $data['normalize_prices'] = filter_var($data['normalize_prices'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
            if (array_key_exists('is_adjustable', $data)) {
                $data['is_adjustable'] = filter_var($data['is_adjustable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }

            // Check code uniqueness if code is being updated
            if (!empty($data['code']) && $data['code'] !== $tender->code) {
                $existing = Tender::getByCode($data['code'], $user->id);
                if ($existing) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tender code already exists',
                    ], 400);
                }
            }

            // Check for changes that affect Po and Evaluation calculations
            $fieldsThatAffectPo = ['po_method', 'tgamma', 'tbeta', 'delta', 'is_adjustable', 'base_period'];
            $fieldsThatAffectEvaluation = ['is_two_stage', 'a_max', 'normalization_factor', 'normalize_prices'];
            
            $shouldInvalidatePo = false;
            $shouldInvalidateEvaluation = false;

            // Check changes in fields that affect Po
            foreach ($fieldsThatAffectPo as $field) {
                if (array_key_exists($field, $data)) {
                    $oldValue = property_exists($tender, $field) ? $tender->$field : null;
                    $newValue = $data[$field];
                    
                    // Normalize boolean values for proper comparison
                    if ($field === 'is_adjustable') {
                        $oldValue = filter_var($oldValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                        $newValue = filter_var($newValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                    }
                    
                    // Direct comparison for boolean and string values
                    if (is_bool($oldValue) || is_string($oldValue)) {
                        if ($oldValue != $newValue) {
                            $shouldInvalidatePo = true;
                            $shouldInvalidateEvaluation = true;
                            break;
                        }
                    } else {
                        // Compare numeric values with tolerance
                        if (abs(floatval($oldValue) - floatval($newValue)) > 0.0001) {
                            $shouldInvalidatePo = true;
                            $shouldInvalidateEvaluation = true;
                            break;
                        }
                    }
                }
            }

            // Check changes in fields that affect Evaluation
            foreach ($fieldsThatAffectEvaluation as $field) {
                if (array_key_exists($field, $data)) {
                    $oldValue = property_exists($tender, $field) ? $tender->$field : null;
                    $newValue = $data[$field];
                    
                    // Normalize boolean values for proper comparison
                    if (in_array($field, ['is_two_stage', 'normalize_prices', 'is_adjustable'])) {
                        $oldValue = filter_var($oldValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                        $newValue = filter_var($newValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                    }
                    
                    if (is_bool($oldValue) || is_string($oldValue)) {
                        if ($oldValue != $newValue) {
                            $shouldInvalidateEvaluation = true;
                            break;
                        }
                    } else {
                        if (abs(floatval($oldValue) - floatval($newValue)) > 0.0001) {
                            $shouldInvalidateEvaluation = true;
                            break;
                        }
                    }
                }
            }

            // If pb is being updated, invalidate Po and evaluation
            if (array_key_exists('pb', $data)) {
                $oldPb = floatval($tender->pb ?? 0);
                $newPb = floatval($data['pb'] ?? 0);
                if (abs($oldPb - $newPb) > 0.01) {
                    $shouldInvalidatePo = true;
                    $shouldInvalidateEvaluation = true;
                }
            }

            // If po is being updated with a new value > 0, only invalidate evaluation (not Po itself)
            if (array_key_exists('po', $data)) {
                $oldPo = floatval($tender->po ?? 0);
                $newPo = floatval($data['po'] ?? 0);
                if (abs($oldPo - $newPo) > 0.01) {
                    if ($newPo > 0) {
                        $shouldInvalidateEvaluation = true;
                    } else {
                        $shouldInvalidatePo = true;
                        $shouldInvalidateEvaluation = true;
                    }
                }
            }

            // Perform update
            $tender->update($data);

            // Invalidate caches if needed
            if ($shouldInvalidatePo) {
                Tender::invalidatePo($id);
            }
            if ($shouldInvalidateEvaluation) {
                Tender::invalidateEvaluation($id);
                EvaluationResult::deleteByTenderId($id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Tender updated successfully',
                'data' => $tender->fresh()->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete tender
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'احراز هویت لازم است',
                ], 401);
            }

            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $tender->delete();
            $this->clearTenderCache($id, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Tender deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate and update Pb for a tender
     */
    public function calculatePb(Request $request, string $id): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $totalPb = Estimate::calculateTotalPb($id);
            $tender->update(['pb' => $totalPb]);

            return response()->json([
                'success' => true,
                'message' => 'Pb calculated successfully',
                'data' => [
                    'tender_id' => $id,
                    'pb' => $totalPb,
                ],
                'tender' => $tender->fresh()->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set Po calculation timestamp
     */
    public function setPoCalculatedAt(Request $request, string $id): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            Tender::setPoCalculatedAt($id);

            // Invalidate evaluation results when Po is recalculated
            Tender::invalidateEvaluation($id);
            EvaluationResult::deleteByTenderId($id);

            return response()->json([
                'success' => true,
                'message' => 'Po calculation timestamp set successfully. Evaluation results have been invalidated.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set evaluation calculation timestamp
     */
    public function setEvaluationCalculatedAt(Request $request, string $id): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            Tender::setEvaluationCalculatedAt($id);

            return response()->json([
                'success' => true,
                'message' => 'Evaluation calculation timestamp set successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Invalidate Po calculation
     */
    public function invalidatePo(Request $request, string $id): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            Tender::invalidatePo($id);
            
            // Invalidate Evaluation when Po is invalidated (Evaluation depends on Po)
            Tender::invalidateEvaluation($id);
            EvaluationResult::deleteByTenderId($id);

            return response()->json([
                'success' => true,
                'message' => 'Po calculation invalidated successfully. Evaluation results have also been invalidated.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Invalidate evaluation results
     */
    public function invalidateEvaluation(Request $request, string $id): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            Tender::invalidateEvaluation($id);
            EvaluationResult::deleteByTenderId($id);

            return response()->json([
                'success' => true,
                'message' => 'Evaluation results invalidated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate Po (برآورد به‌هنگام) for a tender
     */
    public function calculatePo(Request $request, string $id): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            // بررسی اینکه آیا باید force recalculate شود
            $forceRecalculate = $request->input('force', false) === true || $request->input('force', false) === 'true';
            
            $poService = new PoCalculationService();
            $result = $poService->calculate($id, $forceRecalculate);
            
            // اگر از cache آمده، نیازی به ذخیره مجدد نیست
            if (isset($result['from_cache']) && $result['from_cache']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Po retrieved from cache (inputs unchanged)',
                    'data' => $result,
                    'tender' => $tender->fresh()->toArray(),
                    'from_cache' => true,
                ]);
            }

            // بررسی صحت Po قبل از ذخیره
            $po = floatval($result['Po'] ?? 0);
            if ($po <= 0 || is_nan($po) || is_infinite($po)) {
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در محاسبه برآورد به‌هنگام: نتیجه محاسبه معتبر نیست',
                ], 500);
            }

            // ذخیره Po در مناقصه
            $tender->update(['po' => $po, 'pb' => $result['Pb'] ?? 0]);

            // ثبت زمان محاسبه
            Tender::setPoCalculatedAt($id);

            // Invalidate evaluation cache (because Po has changed)
            $cacheService = new CalculationCacheService();
            $cacheService->invalidateEvaluationCache($id);
            Tender::invalidateEvaluation($id);
            EvaluationResult::deleteByTenderId($id);

            return response()->json([
                'success' => true,
                'message' => 'Po calculated successfully',
                'data' => $result,
                'tender' => $tender->fresh()->toArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Po calculation error in controller', [
                'tender_id' => $id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // In development, return detailed error
            // In production, return generic message but log details
            $isProduction = app()->environment('production');
            
            return response()->json([
                'success' => false,
                'message' => $isProduction ? 'خطا در محاسبه برآورد به‌هنگام. لطفاً با پشتیبانی تماس بگیرید.' : $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $isProduction ? null : $e->getFile(),
                'line' => $isProduction ? null : $e->getLine(),
            ], 500);
        }
    }

    /**
     * Calculate evaluation (ارزیابی مالی) for a tender
     */
    public function calculateEvaluation(Request $request, string $id): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $id);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            // بررسی اینکه آیا باید force recalculate شود
            $forceRecalculate = $request->input('force', false) === true || $request->input('force', false) === 'true';

            $evaluationService = new EvaluationService();
            $result = $evaluationService->evaluate($id, $forceRecalculate);

            return response()->json([
                'success' => true,
                'message' => 'Evaluation calculated successfully',
                'data' => $result,
                'tender' => $tender->fresh()->withValidityFlags()->toArray(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in calculateEvaluation', [
                'tender_id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در محاسبه ارزیابی: ' . $e->getMessage() 
                    : 'خطا در محاسبه ارزیابی. لطفاً با مدیر سیستم تماس بگیرید.',
                'error_type' => config('app.debug') ? get_class($e) : null,
                'file' => config('app.debug') ? $e->getFile() . ':' . $e->getLine() : null,
            ], 500);
        }
    }
}


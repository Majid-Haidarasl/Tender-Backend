<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Models\EvaluationResult;
use App\Models\Tender;
use App\Models\Bidder;
use App\Services\TenderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class EvaluationController extends Controller
{
    use AuthorizesOwnedTender;

    private function requireOwnedEvaluation(Request $request, string $id): EvaluationResult|JsonResponse
    {
        $evaluation = EvaluationResult::find($id);

        if (!$evaluation || !TenderAccessService::canAccess($request, $evaluation->tender_id)) {
            return $this->ownedTenderNotFoundResponse();
        }

        return $evaluation;
    }
    /**
     * Get all evaluation results for a tender
     */
    public function getByTenderId(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $evaluations = EvaluationResult::getByTenderId($tenderId);
            
            // اگر ارزیابی انجام شده، محدوده‌های قیمت را از EvaluationService بگیر (بدون محاسبه مجدد)
            $ranges = null;
            if ($tender && $tender->po > 0 && $tender->evaluation_is_valid && !empty($evaluations)) {
                $evaluationService = new \App\Services\EvaluationService();
                try {
                    $evaluationResult = $evaluationService->getResults($tenderId);
                    if (isset($evaluationResult['ranges'])) {
                        $ranges = $evaluationResult['ranges'];
                    }
                } catch (\Exception $e) {
                    Log::warning('Could not get evaluation ranges from stored results', [
                        'tender_id' => $tenderId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $evaluations,
                'count' => count($evaluations),
                'ranges' => $ranges,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get evaluation by ID
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $evaluation = EvaluationResult::getByIdWithBidder($id);

            if (!$evaluation || !TenderAccessService::canAccess($request, $evaluation['tender_id'] ?? '')) {
                return $this->ownedTenderNotFoundResponse();
            }

            return response()->json([
                'success' => true,
                'data' => $evaluation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create new evaluation result
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            // Validation
            if (empty($data['tender_id']) || empty($data['bidder_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: tender_id, bidder_id',
                ], 400);
            }

            // Validate numeric fields if provided
            if (isset($data['normalized_price']) && $data['normalized_price'] !== null) {
                if (!is_numeric($data['normalized_price']) || floatval($data['normalized_price']) < 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'normalized_price must be a valid positive number',
                    ], 400);
                }
            }
            if (isset($data['technical_score']) && $data['technical_score'] !== null) {
                $score = floatval($data['technical_score']);
                if (!is_numeric($data['technical_score']) || $score < 0 || $score > 100) {
                    return response()->json([
                        'success' => false,
                        'message' => 'technical_score must be a valid number between 0 and 100',
                    ], 400);
                }
            }
            if (isset($data['final_score']) && $data['final_score'] !== null) {
                if (!is_numeric($data['final_score']) || floatval($data['final_score']) < 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'final_score must be a valid positive number',
                    ], 400);
                }
            }
            if (isset($data['rank']) && $data['rank'] !== null) {
                if (!is_numeric($data['rank']) || intval($data['rank']) < 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'rank must be a valid positive integer',
                    ], 400);
                }
            }

            // Check if tender exists and belongs to user
            $tender = $this->requireOwnedTender($request, $data['tender_id']);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            // Check if bidder exists
            $bidder = Bidder::find($data['bidder_id']);
            if (!$bidder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bidder not found',
                ], 404);
            }

            // Check if evaluation already exists
            $existing = EvaluationResult::getByTenderAndBidder($data['tender_id'], $data['bidder_id']);
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Evaluation already exists for this tender and bidder',
                ], 400);
            }

            $evaluation = EvaluationResult::create([
                'tender_id' => $data['tender_id'],
                'bidder_id' => $data['bidder_id'],
                'normalized_price' => $data['normalized_price'] ?? null,
                'technical_score' => $data['technical_score'] ?? null,
                'final_score' => $data['final_score'] ?? null,
                'rank' => $data['rank'] ?? null,
                'is_winner' => $data['is_winner'] ?? false,
                'notes' => $data['notes'] ?? '',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Evaluation created successfully',
                'data' => EvaluationResult::getByIdWithBidder($evaluation->id),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update evaluation result
     * این متد برای به‌روزرسانی فیلدهای evaluation_result استفاده می‌شود
     * به خصوص برای ذخیره برنده اول و دوم (is_winner_first, is_winner_second)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $evaluation = $this->requireOwnedEvaluation($request, $id);
            if ($evaluation instanceof JsonResponse) {
                return $evaluation;
            }

            $data = $request->all();
            
            // اطمینان از اینکه is_winner_first و is_winner_second به boolean تبدیل شوند
            $isWinnerFirst = false;
            $isWinnerSecond = false;
            if (isset($data['is_winner_first'])) {
                $isWinnerFirst = filter_var($data['is_winner_first'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($data['is_winner_second'])) {
                $isWinnerSecond = filter_var($data['is_winner_second'], FILTER_VALIDATE_BOOLEAN);
            }
            
            // استفاده از transaction برای اطمینان از یکپارچگی داده‌ها
            DB::beginTransaction();
            
            try {
                $tenderId = $evaluation->tender_id;
                
                // اگر برنده اول تنظیم می‌شود، برنده اول قبلی را پاک کن
                if ($isWinnerFirst) {
                    EvaluationResult::where('tender_id', $tenderId)
                        ->where('id', '!=', $id)
                        ->where('is_winner_first', true)
                        ->update(['is_winner_first' => false]);
                }
                
                // اگر برنده دوم تنظیم می‌شود، برنده دوم قبلی را پاک کن
                if ($isWinnerSecond) {
                    EvaluationResult::where('tender_id', $tenderId)
                        ->where('id', '!=', $id)
                        ->where('is_winner_second', true)
                        ->update(['is_winner_second' => false]);
                }
                
                // به‌روزرسانی evaluation
                $data['is_winner_first'] = $isWinnerFirst;
                $data['is_winner_second'] = $isWinnerSecond;
                $evaluation->update($data);
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Evaluation updated successfully',
                    'data' => EvaluationResult::getByIdWithBidder($id),
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error updating evaluation result', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete evaluation result
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $evaluation = $this->requireOwnedEvaluation($request, $id);
            if ($evaluation instanceof JsonResponse) {
                return $evaluation;
            }

            $evaluation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Evaluation deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk create/update evaluation results
     */
    public function bulkUpsert(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            if (empty($data['tender_id']) || !isset($data['evaluations']) || !is_array($data['evaluations'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: tender_id, evaluations (array)',
                ], 400);
            }

            // Check if tender exists and belongs to user
            $tender = $this->requireOwnedTender($request, $data['tender_id']);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            // Add tender_id to each evaluation
            $evaluationsData = array_map(function ($eval) use ($data) {
                return array_merge($eval, ['tender_id' => $data['tender_id']]);
            }, $data['evaluations']);

            EvaluationResult::bulkUpsert($evaluationsData);

            $updatedEvaluations = EvaluationResult::getByTenderId($data['tender_id']);

            return response()->json([
                'success' => true,
                'message' => 'Evaluations created/updated successfully',
                'data' => $updatedEvaluations,
                'count' => count($updatedEvaluations),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}


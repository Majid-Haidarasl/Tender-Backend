<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Models\Bidder;
use App\Models\BidderPriceItem;
use App\Models\Tender;
use App\Models\EvaluationResult;
use App\Services\Article5Validator;
use App\Services\AlertNotificationService;
use App\Services\CalculationCacheService;
use App\Services\TenderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BidderController extends Controller
{
    use AuthorizesOwnedTender;

    private function requireOwnedBidder(Request $request, string $id): array|JsonResponse
    {
        $bidderData = Bidder::getByIdWithPriceItems($id);

        if (!$bidderData || !TenderAccessService::canAccess($request, $bidderData['tender_id'])) {
            return $this->ownedTenderNotFoundResponse();
        }

        return $bidderData;
    }

    private function requireOwnedBidderModel(Request $request, string $id): Bidder|JsonResponse
    {
        $bidder = Bidder::find($id);

        if (!$bidder || !TenderAccessService::canAccess($request, $bidder->tender_id)) {
            return $this->ownedTenderNotFoundResponse();
        }

        return $bidder;
    }
    /**
     * Get all bidders (filtered by tender_id query param)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tenderId = $request->query('tender_id');
            
            if ($tenderId) {
                if (!TenderAccessService::canAccess($request, $tenderId)) {
                    return $this->ownedTenderNotFoundResponse();
                }

                $bidders = Bidder::getByTenderId($tenderId);
                return response()->json([
                    'success' => true,
                    'data' => $bidders,
                    'count' => count($bidders),
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
     * Get all bidders for a tender
     */
    public function getByTenderId(Request $request, string $tenderId): JsonResponse
    {
        try {
            if (!TenderAccessService::canAccess($request, $tenderId)) {
                return $this->ownedTenderNotFoundResponse();
            }

            $bidders = Bidder::getByTenderId($tenderId);
            
            return response()->json([
                'success' => true,
                'data' => $bidders,
                'count' => count($bidders),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get price items for a bidder
     */
    public function getPriceItems(Request $request, string $id): JsonResponse
    {
        try {
            $bidder = $this->requireOwnedBidderModel($request, $id);
            if ($bidder instanceof JsonResponse) {
                return $bidder;
            }

            $priceItems = Bidder::getPriceItems($id);
            
            return response()->json([
                'success' => true,
                'data' => $priceItems,
                'count' => count($priceItems),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get bidder by ID
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $bidder = $this->requireOwnedBidder($request, $id);
            if ($bidder instanceof JsonResponse) {
                return $bidder;
            }

            return response()->json([
                'success' => true,
                'data' => $bidder,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create new bidder
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'tender_id' => 'required|string|exists:tenders,id',
                'name' => 'required|string|max:255',
                'price' => 'required|numeric|min:0',
                'technical_score' => 'nullable|numeric|min:0|max:100',
                'price_items' => 'nullable|array',
                'price_items.*.description' => 'nullable|string|max:500',
                'price_items.*.amount' => 'nullable|numeric|min:0',
                'price_items.*.amount_in_rials' => 'nullable|numeric|min:0',
            ], [
                'tender_id.required' => 'شناسه مناقصه الزامی است',
                'tender_id.exists' => 'مناقصه یافت نشد',
                'name.required' => 'نام مناقصه‌گر الزامی است',
                'name.max' => 'نام مناقصه‌گر نباید بیشتر از ۲۵۵ کاراکتر باشد',
                'price.required' => 'قیمت پیشنهادی الزامی است',
                'price.numeric' => 'قیمت باید یک عدد معتبر باشد',
                'price.min' => 'قیمت نمی‌تواند منفی باشد',
                'technical_score.numeric' => 'امتیاز فنی باید یک عدد معتبر باشد',
                'technical_score.min' => 'امتیاز فنی نمی‌تواند منفی باشد',
                'technical_score.max' => 'امتیاز فنی نمی‌تواند بیشتر از ۱۰۰ باشد',
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

            $priceItems = $data['price_items'] ?? [];

            // Calculate price from price_items if not provided
            $calculatedPrice = floatval($data['price'] ?? 0);
            if (!$calculatedPrice && count($priceItems) > 0) {
                $calculatedPrice = array_reduce($priceItems, function ($sum, $item) {
                    return $sum + floatval($item['amount_in_rials'] ?? $item['amount'] ?? 0);
                }, 0);
            }

            // کنترل ماده 5 (تبصره‌های 1 و 2) - فقط برای مناقصات ارزی یا ارزی-ریالی
            $tenderType = strtolower($tender->type ?? '');
            $isForexTender = str_contains($tenderType, 'forex') ||
                            str_contains($tenderType, 'ارزی') ||
                            str_contains($tenderType, 'mixed') ||
                            str_contains($tenderType, 'ارزی-ریالی');

            if ($isForexTender) {
                $article5Validator = new Article5Validator();
                $article5Validation = $article5Validator->validateArticle5(
                    $data['tender_id'],
                    $priceItems,
                    $calculatedPrice
                );

                if (!$article5Validation['passed']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'پیشنهاد به دلیل عدم رعایت ماده 5 رد شد',
                        'details' => $article5Validation['errors'],
                        'article5Details' => [
                            'forexCheck' => $article5Validation['forexCheck'],
                            'originCheck' => $article5Validation['originCheck'],
                        ],
                    ], 400);
                }
            }

            $data['price'] = $calculatedPrice;
            $data['technical_score'] = $data['technical_score'] ?? null;
            $data['notes'] = $data['notes'] ?? '';
            $data['price_items'] = $priceItems;

            $bidder = Bidder::createWithPriceItems($data);

            // Invalidate evaluation results when bidders change
            Tender::invalidateEvaluation($data['tender_id']);
            // باطل کردن cache محاسبات Evaluation
            $cacheService = new CalculationCacheService();
            $cacheService->invalidateEvaluationCache($data['tender_id']);
            EvaluationResult::deleteByTenderId($data['tender_id']);

            return response()->json([
                'success' => true,
                'message' => 'Bidder created successfully',
                'data' => $bidder,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update bidder
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $bidderData = $this->requireOwnedBidder($request, $id);
            if ($bidderData instanceof JsonResponse) {
                return $bidderData;
            }

            $data = $request->all();
            $priceItemsProvided = array_key_exists('price_items', $data);
            $priceItems = $data['price_items'] ?? null;

            // Recalculate price if price_items changed
            $calculatedPrice = $data['price'] ?? null;
            if ($priceItemsProvided && $priceItems !== null) {
                $calculatedPrice = array_reduce($priceItems, function ($sum, $item) {
                    return $sum + floatval($item['amount_in_rials'] ?? $item['amount'] ?? 0);
                }, 0);
                $data['price'] = $calculatedPrice;
            }

            // کنترل ماده 5 (تبصره‌های 1 و 2) - فقط برای مناقصات ارزی یا ارزی-ریالی
            $tender = Tender::find($bidderData['tender_id']);
            
            if (!$tender) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tender not found',
                ], 404);
            }
            
            $tenderType = strtolower($tender->type ?? '');
            $isForexTender = $tender && $tender->type && (
                str_contains($tenderType, 'forex') ||
                str_contains($tenderType, 'ارزی') ||
                str_contains($tenderType, 'mixed') ||
                str_contains($tenderType, 'ارزی-ریالی')
            );

            // دقیقاً مثل Node.js: همیشه برای مناقصات ارزی اجرا می‌شود
            if ($isForexTender) {
                $article5Validator = new Article5Validator();
                $article5Validation = $article5Validator->validateArticle5(
                    $bidderData['tender_id'],
                    $priceItems ?? [], // اگر undefined باشد از آرایه خالی استفاده کن
                    $calculatedPrice
                );

                if (!$article5Validation['passed']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'پیشنهاد به دلیل عدم رعایت ماده 5 رد شد',
                        'details' => $article5Validation['errors'],
                        'article5Details' => [
                            'forexCheck' => $article5Validation['forexCheck'],
                            'originCheck' => $article5Validation['originCheck'],
                        ],
                    ], 400);
                }
            }

            $updatedBidder = Bidder::updateWithPriceItems($id, $data);

            // Invalidate evaluation results when bidders change
            Tender::invalidateEvaluation($bidderData['tender_id']);
            // باطل کردن cache محاسبات Evaluation
            $cacheService = new CalculationCacheService();
            $cacheService->invalidateEvaluationCache($bidderData['tender_id']);
            EvaluationResult::deleteByTenderId($bidderData['tender_id']);

            return response()->json([
                'success' => true,
                'message' => 'Bidder updated successfully',
                'data' => $updatedBidder,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete bidder
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $bidder = $this->requireOwnedBidderModel($request, $id);
            if ($bidder instanceof JsonResponse) {
                return $bidder;
            }

            $tenderId = $bidder->tender_id;
            $bidder->delete();

            // Invalidate evaluation results when bidders change
            Tender::invalidateEvaluation($tenderId);
            // باطل کردن cache محاسبات Evaluation
            $cacheService = new CalculationCacheService();
            $cacheService->invalidateEvaluationCache($tenderId);
            EvaluationResult::deleteByTenderId($tenderId);

            return response()->json([
                'success' => true,
                'message' => 'Bidder deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}


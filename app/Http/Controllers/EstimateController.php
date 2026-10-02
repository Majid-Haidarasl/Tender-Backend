<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Models\Estimate;
use App\Models\Tender;
use App\Models\EvaluationResult;
use App\Services\CalculationCacheService;
use App\Services\TenderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateController extends Controller
{
    use AuthorizesOwnedTender;

    private function requireOwnedEstimate(Request $request, string $id): Estimate|JsonResponse
    {
        $estimate = Estimate::find($id);

        if (!$estimate || !TenderAccessService::canAccess($request, $estimate->tender_id)) {
            return $this->ownedTenderNotFoundResponse();
        }

        return $estimate;
    }
    /**
     * Get all estimates (filtered by tender_id query param)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tenderId = $request->query('tender_id');
            
            if ($tenderId) {
                if (!TenderAccessService::canAccess($request, $tenderId)) {
                    return $this->ownedTenderNotFoundResponse();
                }

                $estimates = Estimate::getByTenderId($tenderId);
                return response()->json([
                    'success' => true,
                    'data' => $estimates,
                    'count' => count($estimates),
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
     * Get all estimates for a tender
     */
    public function getByTenderId(Request $request, string $tenderId): JsonResponse
    {
        try {
            if (!TenderAccessService::canAccess($request, $tenderId)) {
                return $this->ownedTenderNotFoundResponse();
            }

            $estimates = Estimate::getByTenderId($tenderId);
            
            return response()->json([
                'success' => true,
                'data' => $estimates,
                'count' => count($estimates),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get estimate by ID
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $estimate = $this->requireOwnedEstimate($request, $id);
            if ($estimate instanceof JsonResponse) {
                return $estimate;
            }

            return response()->json([
                'success' => true,
                'data' => $estimate->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create new estimate
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'tender_id' => 'required|string|exists:tenders,id',
                'section' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|max:10',
                'exchange_rate' => 'nullable|numeric|min:0.01',
                'description' => 'nullable|string|max:1000',
            ], [
                'tender_id.required' => 'شناسه مناقصه الزامی است',
                'tender_id.exists' => 'مناقصه یافت نشد',
                'section.required' => 'بخش برآورد الزامی است',
                'section.max' => 'نام بخش نباید بیشتر از ۲۵۵ کاراکتر باشد',
                'amount.required' => 'مبلغ الزامی است',
                'amount.numeric' => 'مبلغ باید یک عدد معتبر باشد',
                'amount.min' => 'مبلغ باید بزرگتر از صفر باشد',
                'exchange_rate.numeric' => 'نرخ تسعیر باید یک عدد معتبر باشد',
                'exchange_rate.min' => 'نرخ تسعیر باید بزرگتر از صفر باشد',
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

            // Calculate amount_in_rials if not provided
            $currency = $data['currency'] ?? 'IRR';
            $exchangeRate = $data['exchange_rate'] ?? null;
            
            // تبدیل amount به string و پاکسازی - حفظ دقت کامل
            $amountStr = is_string($data['amount']) ? $data['amount'] : (string)$data['amount'];
            // حذف کاراکترهای غیرعددی به جز نقطه
            $amountStr = preg_replace('/[^\d.]/', '', $amountStr);
            
            // بررسی اینکه amount معتبر است
            if (empty($amountStr) || $amountStr === '.' || $amountStr === '0' || $amountStr === '0.') {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ باید یک عدد معتبر و مثبت باشد',
                ], 400);
            }
            
            // بررسی اینکه عدد منفی نیست
            if (strpos($amountStr, '-') !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ باید یک عدد مثبت باشد',
                ], 400);
            }
            
            // برای validation، تبدیل به float (اما ذخیره به صورت string)
            $amountFloat = (float)$amountStr;
            if ($amountFloat <= 0 || is_nan($amountFloat) || is_infinite($amountFloat)) {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ باید یک عدد معتبر و مثبت باشد',
                ], 400);
            }
            
            // محاسبه amount_in_rials - استفاده از bcmath برای حفظ دقت
            if ($currency === 'IRR' || !$exchangeRate) {
                $calculatedAmountInRials = $amountStr; // استفاده مستقیم از string
            } else {
                // تبدیل exchange_rate به string
                $exchangeRateStr = is_string($exchangeRate) ? $exchangeRate : (string)$exchangeRate;
                $exchangeRateStr = preg_replace('/[^\d.]/', '', $exchangeRateStr);
                
                // Validation
                $exchangeRateFloat = (float)$exchangeRateStr;
                if ($exchangeRateFloat <= 0 || is_nan($exchangeRateFloat) || is_infinite($exchangeRateFloat)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'نرخ تسعیر باید یک عدد معتبر و مثبت باشد',
                    ], 400);
                }
                
                // استفاده از bcmath برای محاسبه دقیق
                if (function_exists('bcmul')) {
                    $calculatedAmountInRials = bcmul($amountStr, $exchangeRateStr, 2);
                } else {
                    // اگر bcmath موجود نیست، از float استفاده می‌کنیم
                    $calculatedAmountInRials = number_format((float)$amountStr * $exchangeRateFloat, 2, '.', '');
                }
            }
            
            // استفاده از مقدار محاسبه شده (به صورت string برای حفظ دقت)
            $data['amount'] = $amountStr; // استفاده از string برای حفظ دقت
            $data['amount_in_rials'] = $calculatedAmountInRials;
            
            // Validation نهایی
            $amountInRialsFloat = (float)$calculatedAmountInRials;
            if ($amountInRialsFloat <= 0 || is_nan($amountInRialsFloat) || is_infinite($amountInRialsFloat)) {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ به ریال محاسبه شده معتبر نیست. لطفاً مقادیر را بررسی کنید',
                ], 400);
            }

            // Set defaults
            $data['currency'] = $currency;
            $data['exchange_rate'] = $currency === 'IRR' ? null : $exchangeRate;
            $data['is_adjustable'] = $data['is_adjustable'] ?? false;
            $data['calculation_method'] = $data['calculation_method'] ?? 'base_price_list';
            $data['base_period'] = $data['base_period'] ?? null;
            $data['currency_name'] = ($currency === 'OTHER' && isset($data['currency_name'])) ? $data['currency_name'] : null;
            $data['notes'] = $data['notes'] ?? '';

            $estimate = Estimate::create($data);

            // Update tender's Pb
            $totalPb = Estimate::calculateTotalPb($data['tender_id']);
            $tender->update(['pb' => $totalPb]);

            // باطل کردن cache محاسبات (Po و Evaluation)
            $cacheService = new CalculationCacheService();
            $cacheService->invalidatePoCache($data['tender_id']);
            $cacheService->invalidateEvaluationCache($data['tender_id']);
            Tender::invalidatePo($data['tender_id']);
            Tender::invalidateEvaluation($data['tender_id']);
            EvaluationResult::deleteByTenderId($data['tender_id']);

            return response()->json([
                'success' => true,
                'message' => 'Estimate created successfully',
                'data' => $estimate->toArray(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update estimate
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $estimate = $this->requireOwnedEstimate($request, $id);
            if ($estimate instanceof JsonResponse) {
                return $estimate;
            }

            $data = $request->all();

            // همیشه amount_in_rials را مجدداً محاسبه کن (برای اطمینان از صحت)
            $currency = $data['currency'] ?? $estimate->currency;
            $exchangeRate = $data['exchange_rate'] ?? $estimate->exchange_rate;
            
            // تبدیل amount به string و پاکسازی - حفظ دقت کامل
            $amountValue = $data['amount'] ?? $estimate->amount;
            $amountStr = is_string($amountValue) ? $amountValue : (string)$amountValue;
            // حذف کاراکترهای غیرعددی به جز نقطه
            $amountStr = preg_replace('/[^\d.]/', '', $amountStr);
            
            // بررسی اینکه amount معتبر است
            if (empty($amountStr) || $amountStr === '.' || $amountStr === '0' || $amountStr === '0.') {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ باید یک عدد معتبر و مثبت باشد',
                ], 400);
            }
            
            // بررسی اینکه عدد منفی نیست
            if (strpos($amountStr, '-') !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ باید یک عدد مثبت باشد',
                ], 400);
            }
            
            // برای validation، تبدیل به float
            $amount = (float)$amountStr;
            if ($amount <= 0 || is_nan($amount) || is_infinite($amount)) {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ باید یک عدد معتبر و مثبت باشد',
                ], 400);
            }
            
            // محاسبه amount_in_rials - استفاده از bcmath برای حفظ دقت
            if ($currency === 'IRR' || !$exchangeRate) {
                $calculatedAmountInRials = $amountStr; // استفاده مستقیم از string
            } else {
                // تبدیل exchange_rate به string
                $exchangeRateValue = $exchangeRate;
                $exchangeRateStr = is_string($exchangeRateValue) ? $exchangeRateValue : (string)$exchangeRateValue;
                $exchangeRateStr = preg_replace('/[^\d.]/', '', $exchangeRateStr);
                $exchangeRateFloat = (float)$exchangeRateStr;
                
                if ($exchangeRateFloat <= 0 || is_nan($exchangeRateFloat) || is_infinite($exchangeRateFloat)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'نرخ تسعیر باید یک عدد معتبر و مثبت باشد',
                    ], 400);
                }
                
                // استفاده از bcmath برای محاسبه دقیق
                if (function_exists('bcmul')) {
                    $calculatedAmountInRials = bcmul($amountStr, $exchangeRateStr, 2);
                } else {
                    // اگر bcmath موجود نیست، از float استفاده می‌کنیم
                    $calculatedAmountInRials = number_format((float)$amountStr * $exchangeRateFloat, 2, '.', '');
                }
            }
            
            // استفاده از مقدار محاسبه شده (به صورت string برای حفظ دقت)
            $data['amount'] = $amountStr; // استفاده از string برای حفظ دقت
            $data['amount_in_rials'] = $calculatedAmountInRials;
            
            // Validate amount_in_rials
            $amountInRialsFloat = (float)$calculatedAmountInRials;
            if ($amountInRialsFloat <= 0 || is_nan($amountInRialsFloat) || is_infinite($amountInRialsFloat)) {
                return response()->json([
                    'success' => false,
                    'message' => 'مبلغ به ریال محاسبه شده معتبر نیست. لطفاً مقادیر را بررسی کنید',
                ], 400);
            }

            // Handle currency_name: set to null if currency is not 'OTHER'
            if (array_key_exists('currency', $data)) {
                if ($data['currency'] !== 'OTHER') {
                    $data['currency_name'] = null;
                } elseif (!array_key_exists('currency_name', $data)) {
                    // Keep existing currency_name if currency changed to OTHER but currency_name not provided
                    $data['currency_name'] = $estimate->currency_name;
                }
            }

            $estimate->update($data);

            // Update tender's Pb
            $totalPb = Estimate::calculateTotalPb($estimate->tender_id);
            Tender::where('id', $estimate->tender_id)->update(['pb' => $totalPb]);

            // باطل کردن cache محاسبات (Po و Evaluation)
            $cacheService = new CalculationCacheService();
            $cacheService->invalidatePoCache($estimate->tender_id);
            $cacheService->invalidateEvaluationCache($estimate->tender_id);
            Tender::invalidatePo($estimate->tender_id);
            Tender::invalidateEvaluation($estimate->tender_id);
            EvaluationResult::deleteByTenderId($estimate->tender_id);

            return response()->json([
                'success' => true,
                'message' => 'Estimate updated successfully',
                'data' => $estimate->fresh()->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete estimate
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $estimate = $this->requireOwnedEstimate($request, $id);
            if ($estimate instanceof JsonResponse) {
                return $estimate;
            }

            $tenderId = $estimate->tender_id;
            $estimate->delete();

            // Update tender's Pb
            $totalPb = Estimate::calculateTotalPb($tenderId);
            Tender::where('id', $tenderId)->update(['pb' => $totalPb]);

            // باطل کردن cache محاسبات (Po و Evaluation)
            $cacheService = new CalculationCacheService();
            $cacheService->invalidatePoCache($tenderId);
            $cacheService->invalidateEvaluationCache($tenderId);
            Tender::invalidatePo($tenderId);
            Tender::invalidateEvaluation($tenderId);
            EvaluationResult::deleteByTenderId($tenderId);

            return response()->json([
                'success' => true,
                'message' => 'Estimate deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}


<?php

namespace App\Http\Controllers;

use App\Models\Formula;
use App\Services\FormulaService;
use App\Services\CalculationCacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class FormulaController extends Controller
{
    /**
     * Get all formulas
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $category = $request->query('category');
            $isActive = $request->query('is_active');
            
            $query = Formula::query();
            
            if ($category) {
                $query->where('category', $category);
            }
            
            if ($isActive !== null) {
                $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
            }
            
            $formulas = $query->orderBy('category')->orderBy('name')->get();
            
            return response()->json([
                'success' => true,
                'data' => $formulas,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching formulas', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در دریافت فرمول‌ها: ' . $e->getMessage() 
                    : 'خطا در دریافت فرمول‌ها',
            ], 500);
        }
    }

    /**
     * Get a specific formula
     */
    public function show(string $id): JsonResponse
    {
        try {
            $formula = Formula::find($id);
            
            if (!$formula) {
                return response()->json([
                    'success' => false,
                    'message' => 'فرمول یافت نشد',
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $formula,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching formula', [
                'formula_id' => $id,
                'message' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در دریافت فرمول: ' . $e->getMessage() 
                    : 'خطا در دریافت فرمول',
            ], 500);
        }
    }

    /**
     * Create a new formula
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:formulas,name',
                'category' => 'required|string|max:255',
                'description' => 'nullable|string',
                'formula' => 'required|string',
                'parameters' => 'nullable|array',
                'conditions' => 'nullable|array',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $user = $request->user();
            
            // SECURITY: User must be authenticated (handled by middleware, but double-check)
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'احراز هویت لازم است',
                ], 401);
            }
            
            $formula = Formula::create([
                'name' => $request->input('name'),
                'category' => $request->input('category'),
                'description' => $request->input('description'),
                'formula' => $request->input('formula'),
                'parameters' => $request->input('parameters'),
                'conditions' => $request->input('conditions'),
                'is_active' => $request->input('is_active', true),
                'is_default' => false,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            // پاک کردن cache فرمول‌ها
            FormulaService::clearCache();
            
            // باطل کردن cache محاسبات برای همه مناقصات
            $this->invalidateAllCalculationCaches();

            return response()->json([
                'success' => true,
                'message' => 'فرمول با موفقیت ایجاد شد',
                'data' => $formula,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating formula', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در ایجاد فرمول: ' . $e->getMessage() 
                    : 'خطا در ایجاد فرمول',
            ], 500);
        }
    }

    /**
     * Update a formula
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $formula = Formula::find($id);
            
            if (!$formula) {
                return response()->json([
                    'success' => false,
                    'message' => 'فرمول یافت نشد',
                ], 404);
            }

            // حذف محدودیت ویرایش فرمول‌های پیش‌فرض - همه فرمول‌ها قابل ویرایش هستند

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255|unique:formulas,name,' . $id,
                'category' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'formula' => 'sometimes|string',
                'parameters' => 'nullable|array',
                'conditions' => 'nullable|array',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $user = $request->user();
            
            // SECURITY: User must be authenticated (handled by middleware, but double-check)
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'احراز هویت لازم است',
                ], 401);
            }
            
            // Increment version
            $formula->version = ($formula->version ?? 1) + 1;
            $formula->updated_by = $user->id;
            
            $formula->fill($request->only([
                'name', 'category', 'description', 'formula',
                'parameters', 'conditions', 'is_active'
            ]));
            
            $formula->save();

            // پاک کردن cache فرمول‌ها
            FormulaService::clearCache();
            
            // باطل کردن cache محاسبات برای همه مناقصات
            // چون فرمول‌ها در محاسبات استفاده می‌شوند، باید همه محاسبات دوباره انجام شوند
            $this->invalidateAllCalculationCaches();

            return response()->json([
                'success' => true,
                'message' => 'فرمول با موفقیت به‌روزرسانی شد',
                'data' => $formula,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating formula', [
                'formula_id' => $id,
                'message' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در به‌روزرسانی فرمول: ' . $e->getMessage() 
                    : 'خطا در به‌روزرسانی فرمول',
            ], 500);
        }
    }

    /**
     * Delete a formula
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $formula = Formula::find($id);
            
            if (!$formula) {
                return response()->json([
                    'success' => false,
                    'message' => 'فرمول یافت نشد',
                ], 404);
            }

            // حذف محدودیت حذف فرمول‌های پیش‌فرض - همه فرمول‌ها قابل حذف هستند

            $formula->delete();

            // پاک کردن cache فرمول‌ها
            FormulaService::clearCache();
            
            // باطل کردن cache محاسبات برای همه مناقصات
            $this->invalidateAllCalculationCaches();

            return response()->json([
                'success' => true,
                'message' => 'فرمول با موفقیت حذف شد',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting formula', [
                'formula_id' => $id,
                'message' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در حذف فرمول: ' . $e->getMessage() 
                    : 'خطا در حذف فرمول',
            ], 500);
        }
    }

    /**
     * Reset formulas to default
     */
    public function resetToDefault(): JsonResponse
    {
        try {
            DB::beginTransaction();
            
            // Deactivate all non-default formulas
            Formula::where('is_default', false)->update(['is_active' => false]);
            
            // Activate all default formulas
            Formula::where('is_default', true)->update(['is_active' => true]);
            
            // پاک کردن cache فرمول‌ها
            FormulaService::clearCache();
            
            // باطل کردن cache محاسبات برای همه مناقصات
            $this->invalidateAllCalculationCaches();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'فرمول‌ها با موفقیت به حالت پیش‌فرض بازگردانده شدند',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error resetting formulas', [
                'message' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در بازگردانی فرمول‌ها: ' . $e->getMessage() 
                    : 'خطا در بازگردانی فرمول‌ها',
            ], 500);
        }
    }

    /**
     * Seed default formulas
     * اجرای seeder برای اضافه کردن فرمول‌های پیش‌فرض
     */
    public function seed(): JsonResponse
    {
        try {
            $output = [];
            $output[] = "Running DefaultFormulasSeeder...";
            $output[] = "--------------------------------------------------";
            
            // اجرای seeder
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\DefaultFormulasSeeder',
                '--force' => true,
            ]);
            
            $seederOutput = Artisan::output();
            $output[] = trim($seederOutput);
            
            // شمارش فرمول‌های موجود
            $totalFormulas = Formula::count();
            $activeFormulas = Formula::where('is_active', true)->count();
            $defaultFormulas = Formula::where('is_default', true)->count();
            
            // پاک کردن cache فرمول‌ها
            FormulaService::clearCache();
            
            // باطل کردن cache محاسبات
            $this->invalidateAllCalculationCaches();
            
            Log::info('Formulas seeded successfully', [
                'total' => $totalFormulas,
                'active' => $activeFormulas,
                'default' => $defaultFormulas
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'فرمول‌های پیش‌فرض با موفقیت اضافه شدند',
                'data' => [
                    'total_formulas' => $totalFormulas,
                    'active_formulas' => $activeFormulas,
                    'default_formulas' => $defaultFormulas,
                    'output' => $output
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error seeding formulas', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در اجرای seeder: ' . $e->getMessage() 
                    : 'خطا در اجرای seeder',
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * باطل کردن cache محاسبات برای همه مناقصات
     * وقتی فرمول تغییر می‌کند، باید همه محاسبات دوباره انجام شوند
     */
    private function invalidateAllCalculationCaches(): void
    {
        try {
            $cacheService = new CalculationCacheService();
            
            // دریافت همه مناقصات
            $tenders = \App\Models\Tender::all();
            
            foreach ($tenders as $tender) {
                // باطل کردن cache Po
                $cacheService->invalidatePoCache($tender->id);
                
                // باطل کردن cache Evaluation
                $cacheService->invalidateEvaluationCache($tender->id);
            }
            
            Log::info('All calculation caches invalidated due to formula change');
        } catch (\Exception $e) {
            Log::error('Error invalidating calculation caches', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}


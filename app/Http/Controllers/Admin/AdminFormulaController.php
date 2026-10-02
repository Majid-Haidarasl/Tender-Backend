<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Services\AdminAuditService;
use App\Services\CalculationCacheService;
use App\Services\FormulaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminFormulaController extends Controller
{
    public function versions(string $id): JsonResponse
    {
        $formula = Formula::find($id);
        if (!$formula) {
            return $this->notFoundResponse('فرمول یافت نشد');
        }

        $versions = FormulaVersion::where('formula_id', $id)
            ->with('creator:id,username,full_name')
            ->orderByDesc('version')
            ->get();

        return $this->successResponse($versions);
    }

    public function saveDraft(Request $request, string $id): JsonResponse
    {
        $formula = Formula::find($id);
        if (!$formula) {
            return $this->notFoundResponse('فرمول یافت نشد');
        }

        $formula->draft_data = $request->only(['name', 'category', 'description', 'formula', 'parameters', 'conditions']);
        $formula->status = 'draft';
        $formula->updated_by = $request->user()->id;
        $formula->save();

        return $this->successResponse($formula, 'پیش‌نویس ذخیره شد');
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $formula = Formula::find($id);
        if (!$formula) {
            return $this->notFoundResponse('فرمول یافت نشد');
        }

        $admin = $request->user();

        FormulaVersion::create([
            'id' => (string) Str::uuid(),
            'formula_id' => $formula->id,
            'version' => $formula->version ?? 1,
            'snapshot' => $formula->toArray(),
            'created_by' => $admin->id,
            'change_note' => $request->input('change_note', 'انتشار نسخه'),
        ]);

        if ($formula->draft_data) {
            $formula->fill($formula->draft_data);
            $formula->draft_data = null;
        }

        $formula->version = ($formula->version ?? 1) + 1;
        $formula->status = 'published';
        $formula->updated_by = $admin->id;
        $formula->save();

        FormulaService::clearCache();
        $this->invalidateCaches();

        AdminAuditService::log(
            $admin->id, 'formula.publish', 'formula', $formula->id,
            null, ['version' => $formula->version], "انتشار فرمول {$formula->name}", $request
        );

        return $this->successResponse($formula, 'فرمول منتشر شد');
    }

    public function rollback(Request $request, string $id, int $version): JsonResponse
    {
        $formula = Formula::find($id);
        if (!$formula) {
            return $this->notFoundResponse('فرمول یافت نشد');
        }

        $versionRecord = FormulaVersion::where('formula_id', $id)->where('version', $version)->first();
        if (!$versionRecord) {
            return $this->notFoundResponse('نسخه یافت نشد');
        }

        $admin = $request->user();
        $snapshot = $versionRecord->snapshot;

        FormulaVersion::create([
            'id' => (string) Str::uuid(),
            'formula_id' => $formula->id,
            'version' => $formula->version ?? 1,
            'snapshot' => $formula->toArray(),
            'created_by' => $admin->id,
            'change_note' => "بازگردانی به نسخه {$version}",
        ]);

        $formula->fill(collect($snapshot)->only([
            'name', 'category', 'description', 'formula', 'parameters', 'conditions', 'is_active',
        ])->toArray());
        $formula->version = ($formula->version ?? 1) + 1;
        $formula->status = 'published';
        $formula->updated_by = $admin->id;
        $formula->save();

        FormulaService::clearCache();
        $this->invalidateCaches();

        AdminAuditService::log(
            $admin->id, 'formula.rollback', 'formula', $formula->id,
            null, ['rolled_back_to' => $version], "بازگردانی فرمول {$formula->name}", $request
        );

        return $this->successResponse($formula, 'فرمول بازگردانی شد');
    }

    public function test(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'formula' => 'required|string',
            'variables' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $result = FormulaService::evaluateFormula(
                $request->input('formula'),
                $request->input('variables', [])
            );

            return $this->successResponse([
                'result' => $result,
                'variables' => $request->input('variables', []),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('خطا در ارزیابی فرمول: ' . $e->getMessage(), 400);
        }
    }

    private function invalidateCaches(): void
    {
        try {
            $cacheService = new CalculationCacheService();
            foreach (Formula::query()->pluck('id') as $ignored) {
                // placeholder - invalidate all tenders
            }
            foreach (\App\Models\Tender::all() as $tender) {
                $cacheService->invalidatePoCache($tender->id);
                $cacheService->invalidateEvaluationCache($tender->id);
            }
        } catch (\Exception $e) {
            // silent
        }
    }
}

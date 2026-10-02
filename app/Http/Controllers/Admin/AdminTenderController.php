<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\AdminAuditService;
use App\Services\CalculationCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTenderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tender::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%");
            });
        }

        $tenders = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 20));

        return $this->paginatedResponse($tenders);
    }

    public function show(string $id): JsonResponse
    {
        $tender = Tender::with(['estimates', 'bidders', 'evaluationResults'])->find($id);
        if (!$tender) {
            return $this->notFoundResponse('مناقصه یافت نشد');
        }
        return $this->successResponse($tender);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $tender = Tender::find($id);
        if (!$tender) {
            return $this->notFoundResponse('مناقصه یافت نشد');
        }

        $tender->status = 'archived';
        $tender->save();

        AdminAuditService::log(
            $request->user()->id, 'tender.archive', 'tender', $tender->id,
            null, ['status' => 'archived'], "آرشیو مناقصه {$tender->code}", $request
        );

        return $this->successResponse($tender, 'مناقصه آرشیو شد');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $tender = Tender::find($id);
        if (!$tender) {
            return $this->notFoundResponse('مناقصه یافت نشد');
        }

        $tender->status = 'active';
        $tender->save();

        AdminAuditService::log(
            $request->user()->id, 'tender.restore', 'tender', $tender->id,
            null, ['status' => 'active'], "بازیابی مناقصه {$tender->code}", $request
        );

        return $this->successResponse($tender, 'مناقصه بازیابی شد');
    }

    public function invalidatePo(Request $request, string $id): JsonResponse
    {
        $tender = Tender::find($id);
        if (!$tender) {
            return $this->notFoundResponse('مناقصه یافت نشد');
        }

        $reason = $request->input('reason', 'باطل‌سازی توسط مدیر');
        Tender::invalidatePo($id);

        AdminAuditService::log(
            $request->user()->id, 'tender.invalidate_po', 'tender', $tender->id,
            null, ['reason' => $reason], "باطل‌سازی Po مناقصه {$tender->code}", $request
        );

        return $this->successResponse($tender->fresh(), 'محاسبه Po باطل شد');
    }

    public function invalidateEvaluation(Request $request, string $id): JsonResponse
    {
        $tender = Tender::find($id);
        if (!$tender) {
            return $this->notFoundResponse('مناقصه یافت نشد');
        }

        $reason = $request->input('reason', 'باطل‌سازی توسط مدیر');
        Tender::invalidateEvaluation($id);

        AdminAuditService::log(
            $request->user()->id, 'tender.invalidate_evaluation', 'tender', $tender->id,
            null, ['reason' => $reason], "باطل‌سازی ارزیابی مناقصه {$tender->code}", $request
        );

        return $this->successResponse($tender->fresh(), 'ارزیابی باطل شد');
    }

    public function workflowStatus(): JsonResponse
    {
        $total = Tender::count();
        $withPb = Tender::where('pb', '>', 0)->count();
        $withPo = Tender::whereNotNull('po_calculated_at')->count();
        $withEvaluation = Tender::whereNotNull('evaluation_calculated_at')->count();
        $archived = Tender::where('status', 'archived')->count();

        return $this->successResponse([
            'total' => $total,
            'with_pb' => $withPb,
            'with_po' => $withPo,
            'with_evaluation' => $withEvaluation,
            'archived' => $archived,
            'stuck_at_pb' => $total - $withPb,
            'stuck_at_po' => $withPb - $withPo,
            'stuck_at_evaluation' => $withPo - $withEvaluation,
        ]);
    }
}

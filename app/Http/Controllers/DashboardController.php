<?php

namespace App\Http\Controllers;

use App\Models\Bidder;
use App\Models\Estimate;
use App\Models\EvaluationResult;
use App\Models\Tender;
use App\Services\TenderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Morilog\Jalali\Jalalian;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $userId = TenderAccessService::userId($request);

        if (!$userId) {
            return $this->errorResponse('توکن احراز هویت یافت نشد', 401);
        }

        $cacheKey = TenderAccessService::dashboardCacheKey($userId);

        $payload = Cache::remember($cacheKey, 30, function () use ($userId) {
            return $this->buildStatsPayload($userId);
        });

        return $this->successResponse($payload);
    }

    private function buildStatsPayload(string $userId): array
    {
        $tenderQuery = fn () => Tender::query()->where('user_id', $userId);

        $statusCounts = [
            'active' => $tenderQuery()->where('status', 'active')->count(),
            'completed' => $tenderQuery()->where('status', 'completed')->count(),
            'cancelled' => $tenderQuery()->where('status', 'cancelled')->count(),
            'pending' => $tenderQuery()->whereNotIn('status', ['active', 'completed', 'cancelled'])->count(),
        ];

        $completedCount = $statusCounts['completed'];

        $completedWithWinners = $tenderQuery()
            ->where('status', 'completed')
            ->whereHas('evaluationResults', fn ($q) => $q->where('is_winner', true))
            ->count();

        $successRate = $completedCount > 0
            ? round(($completedWithWinners / $completedCount) * 100, 1)
            : 0.0;

        $ownedTenderIds = $tenderQuery()->pluck('id');

        $estimateSums = Estimate::query()
            ->whereIn('tender_id', $ownedTenderIds)
            ->selectRaw('tender_id, SUM(amount_in_rials) as total')
            ->groupBy('tender_id')
            ->pluck('total', 'tender_id');

        $tenders = $tenderQuery()
            ->select('id', 'date', 'status', 'type', 'pb')
            ->get();

        $monthlyData = [];
        $departmentData = [];
        $totalFinancialValue = 0.0;
        $wonFinancialValue = 0.0;

        foreach ($tenders as $tender) {
            if ($tender->date) {
                try {
                    $jalali = Jalalian::fromFormat('Y/m/d', $tender->date);
                    $monthKey = $jalali->format('Y/m');
                    $monthlyData[$monthKey] = ($monthlyData[$monthKey] ?? 0) + 1;
                } catch (\Throwable) {
                    // skip invalid dates
                }
            }

            $deptKey = $tender->type ?: 'سایر';
            $departmentData[$deptKey] = ($departmentData[$deptKey] ?? 0) + 1;

            $pb = (float) ($tender->pb ?? 0);
            $estimateTotal = (float) ($estimateSums[$tender->id] ?? 0);
            $tenderValue = $pb > 0 ? $pb : $estimateTotal;
            $totalFinancialValue += $tenderValue;

            if ($tender->status === 'completed') {
                $hasWinner = EvaluationResult::where('tender_id', $tender->id)
                    ->where('is_winner', true)
                    ->exists();

                if ($hasWinner) {
                    $wonFinancialValue += $tenderValue;
                }
            }
        }

        ksort($monthlyData);

        $ownedEstimateQuery = Estimate::query()->whereIn('tender_id', $ownedTenderIds);
        $ownedBidderQuery = Bidder::query()->whereIn('tender_id', $ownedTenderIds);
        $ownedEvaluationQuery = EvaluationResult::query()->whereIn('tender_id', $ownedTenderIds);

        return [
            'stats' => [
                'tenders' => $tenders->count(),
                'activeTenders' => $statusCounts['active'],
                'completedTenders' => $completedCount,
                'estimates' => (clone $ownedEstimateQuery)->count(),
                'bidders' => (clone $ownedBidderQuery)->count(),
                'evaluations' => (clone $ownedEvaluationQuery)->count(),
                'winners' => (clone $ownedEvaluationQuery)->where('is_winner', true)->count(),
                'winnersFirst' => (clone $ownedEvaluationQuery)->where('is_winner_first', true)->count(),
                'winnersSecond' => (clone $ownedEvaluationQuery)->where('is_winner_second', true)->count(),
                'successRate' => $successRate,
                'totalFinancialValue' => $totalFinancialValue,
                'wonFinancialValue' => $wonFinancialValue,
            ],
            'chartData' => [
                'trendData' => [
                    'labels' => array_keys($monthlyData),
                    'values' => array_values($monthlyData),
                ],
                'statusData' => $statusCounts,
                'departmentData' => $departmentData,
            ],
        ];
    }
}

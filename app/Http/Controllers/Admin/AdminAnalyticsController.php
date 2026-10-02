<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Bidder;
use App\Models\Estimate;
use App\Models\EvaluationResult;
use App\Models\Formula;
use App\Models\InputHistory;
use App\Models\Message;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    public function index(): JsonResponse
    {
        $tendersByStatus = Tender::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $tendersByPoMethod = Tender::select('po_method', DB::raw('COUNT(*) as count'))
            ->groupBy('po_method')
            ->pluck('count', 'po_method');

        $poCalculated = Tender::whereNotNull('po_calculated_at')->count();
        $evaluationCalculated = Tender::whereNotNull('evaluation_calculated_at')->count();

        $userActivity = User::select('id', 'username', 'full_name', 'last_login_at', 'created_at')
            ->where('role', 'user')
            ->orderByDesc('last_login_at')
            ->limit(20)
            ->get();

        $formulaUsage = Formula::select('category', DB::raw('COUNT(*) as count'))
            ->where('is_active', true)
            ->groupBy('category')
            ->pluck('count', 'category');

        $recentAudit = AuditTrail::orderByDesc('created_at')->limit(20)->get();

        $monthlyTenders = Tender::select(
            DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->successResponse([
            'tenders_by_status' => $tendersByStatus,
            'tenders_by_po_method' => $tendersByPoMethod,
            'po_calculated_count' => $poCalculated,
            'evaluation_calculated_count' => $evaluationCalculated,
            'totals' => [
                'tenders' => Tender::count(),
                'users' => User::where('role', 'user')->count(),
                'estimates' => Estimate::count(),
                'bidders' => Bidder::count(),
                'evaluations' => EvaluationResult::count(),
                'messages' => Message::count(),
                'audit_trails' => AuditTrail::count(),
                'input_history' => InputHistory::count(),
            ],
            'user_activity' => $userActivity,
            'formula_usage' => $formulaUsage,
            'recent_audit_trails' => $recentAudit,
            'monthly_tenders' => $monthlyTenders,
        ]);
    }

    public function userActivity(string $userId): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) {
            return $this->notFoundResponse('کاربر یافت نشد');
        }

        return $this->successResponse([
            'user' => $user->only(['id', 'username', 'full_name', 'last_login_at', 'created_at']),
            'messages_sent' => Message::where('user_id', $userId)->count(),
            'messages_received' => Message::where('recipient_id', $userId)->count(),
            'audit_trails' => AuditTrail::where('user_id', $userId)->orderByDesc('created_at')->limit(50)->get(),
            'input_history' => InputHistory::where('user_id', $userId)->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }
}

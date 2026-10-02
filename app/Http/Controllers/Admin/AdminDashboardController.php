<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AuditTrail;
use App\Models\Bidder;
use App\Models\Estimate;
use App\Models\EvaluationResult;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Tender;
use App\Models\User;
use App\Services\AdminPermissionService;
use App\Services\SupportContactService;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userStats = [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'admins' => User::where('role', 'admin')->count(),
            'users' => User::where('role', 'user')->count(),
        ];

        $tenderStats = [
            'total' => Tender::count(),
            'po_valid' => Tender::whereNotNull('po_calculated_at')->count(),
            'evaluation_valid' => Tender::whereNotNull('evaluation_calculated_at')->count(),
        ];

        $messageStats = [
            'total' => Message::count(),
            'pending' => Message::where('status', 'pending')->count(),
            'support_pending' => SupportContactService::applySupportInboxFilter(
                Message::where('status', 'pending')
            )->count(),
        ];

        $recentUsers = User::select('id', 'username', 'full_name', 'role', 'is_active', 'last_login_at', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentTenders = Tender::select('id', 'code', 'title', 'status', 'po_calculated_at', 'evaluation_calculated_at', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentAudit = AdminAuditLog::with('admin:id,username,full_name')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return $this->successResponse([
            'users' => $userStats,
            'tenders' => $tenderStats,
            'messages' => $messageStats,
            'counts' => [
                'estimates' => Estimate::count(),
                'bidders' => Bidder::count(),
                'evaluations' => EvaluationResult::count(),
                'notifications' => Notification::count(),
                'audit_trails' => AuditTrail::count(),
            ],
            'recent_users' => $recentUsers,
            'recent_tenders' => $recentTenders,
            'recent_admin_audit' => $recentAudit,
            'permissions' => AdminPermissionService::getPermissions($request->user()),
        ]);
    }

    public function health(): JsonResponse
    {
        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $dbOk = false;
        }

        return $this->successResponse([
            'app_version' => SystemSettingsService::get('app_version', config('release.version', '3.0.0')),
            'database' => $dbOk ? 'connected' : 'disconnected',
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ]);
    }
}

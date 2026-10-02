<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AdminAuditLog::with('admin:id,username,full_name');

        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
        }
        if ($adminId = $request->query('admin_id')) {
            $query->where('admin_id', $adminId);
        }
        if ($targetType = $request->query('target_type')) {
            $query->where('target_type', $targetType);
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 50));

        return $this->paginatedResponse($logs);
    }

    public function show(string $id): JsonResponse
    {
        $log = AdminAuditLog::with('admin:id,username,full_name')->find($id);
        if (!$log) {
            return $this->notFoundResponse('لاگ یافت نشد');
        }
        return $this->successResponse($log);
    }
}

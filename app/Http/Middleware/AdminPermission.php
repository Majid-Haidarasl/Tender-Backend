<?php

namespace App\Http\Middleware;

use App\Services\AdminPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminPermission
{
    public function handle(Request $request, Closure $next, string $permission = 'dashboard'): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'احراز هویت لازم است',
            ], 401);
        }

        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'دسترسی محدود به مدیر سیستم',
            ], 403);
        }

        if (!AdminPermissionService::hasPermission($user, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی لازم برای این عملیات را ندارید',
            ], 403);
        }

        return $next($request);
    }
}

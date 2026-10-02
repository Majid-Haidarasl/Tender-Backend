<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    /**
     * Get all notifications
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // بررسی وجود جدول notifications
            if (!Schema::hasTable('notifications')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'count' => 0,
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 10,
                        'total' => 0,
                        'from' => null,
                        'to' => null,
                    ],
                ]);
            }

            $authUser = $request->user();
            $userId = $authUser?->id ?? $request->query('user_id');
            $type = $request->query('type');
            $read = $request->query('read');
            $limit = $request->query('limit');
            $page = $request->query('page', 1);
            $perPage = $request->query('per_page', 10);

            if (!$userId) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'count' => 0,
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => (int) $perPage,
                        'total' => 0,
                        'from' => null,
                        'to' => null,
                    ],
                ]);
            }

            // Convert read string to boolean if provided
            $readBool = null;
            if ($read !== null) {
                $readBool = filter_var($read, FILTER_VALIDATE_BOOLEAN);
            }

            // If limit is provided, use simple limit (for NotificationBell - 5 latest)
            if ($limit !== null) {
                $notifications = Notification::getAll($userId, $type, $readBool, (int)$limit);
                return response()->json([
                    'success' => true,
                    'data' => $notifications->toArray(),
                    'count' => $notifications->count(),
                ]);
            }

            // Otherwise, use pagination (for NotificationHistory)
            $query = Notification::inboxFor($userId);
            
            if ($type) {
                $query->where('type', $type);
            }
            
            if ($readBool !== null) {
                $query->where('read', $readBool);
            }
            
            $query->orderBy('created_at', 'desc');
            
            $paginated = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'from' => $paginated->firstItem(),
                    'to' => $paginated->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in NotificationController::index', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در دریافت اعلان‌ها: ' . $e->getMessage() 
                    : 'خطا در دریافت اعلان‌ها',
            ], 500);
        }
    }

    /**
     * Get unread count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        try {
            // بررسی وجود جدول notifications
            if (!Schema::hasTable('notifications')) {
                return response()->json([
                    'success' => true,
                    'count' => 0,
                ]);
            }

            $userId = $request->user()?->id ?? $request->query('user_id');
            if (!$userId) {
                return response()->json(['success' => true, 'count' => 0]);
            }

            $count = Notification::getUnreadCount($userId);

            return response()->json([
                'success' => true,
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new notification
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'ثبت اعلان از طریق کلاینت مجاز نیست. فقط پیام‌های مدیریتی نمایش داده می‌شوند.',
        ], 403);
    }

    /**
     * Get a specific notification
     */
    public function show(string $id): JsonResponse
    {
        try {
            $notification = Notification::find($id);

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $notification,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(string $id): JsonResponse
    {
        try {
            $notification = Notification::find($id);

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], 404);
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data' => $notification,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()?->id ?? $request->query('user_id');
            if (!$userId) {
                return response()->json(['success' => true, 'message' => '0 notifications marked as read', 'count' => 0]);
            }
            
            $count = Notification::inboxFor($userId)
                ->where('read', false)
                ->update([
                'read' => true,
                'read_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$count} notifications marked as read",
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a notification
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $notification = Notification::find($id);

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], 404);
            }

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete all notifications
     */
    public function clearAll(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()?->id ?? $request->query('user_id');
            if (!$userId) {
                return response()->json(['success' => true, 'message' => '0 notifications deleted', 'count' => 0]);
            }
            
            $query = Notification::inboxFor($userId);
            $count = $query->count();
            $query->delete();

            return response()->json([
                'success' => true,
                'message' => "{$count} notifications deleted",
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}


<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnedTender;
use App\Services\AlertNotificationService;
use App\Services\TenderAccessService;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    use AuthorizesOwnedTender;

    private $alertService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
    }

    /**
     * دریافت تمام پیام‌های یک مناقصه
     */
    public function getTenderMessages(string $tenderId, Request $request): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $type = $request->query('type'); // WARNING, ERROR, INFO
            $read = $request->query('read'); // true, false
            
            $messages = $this->alertService->getTenderMessages($tenderId, $type);
            
            // فیلتر بر اساس read
            if ($read !== null) {
                $readBool = filter_var($read, FILTER_VALIDATE_BOOLEAN);
                $messages = array_filter($messages, function($msg) use ($readBool) {
                    return ($msg['read'] ?? false) === $readBool;
                });
            }

            return response()->json([
                'success' => true,
                'data' => array_values($messages),
                'count' => count($messages),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت پیام بر اساس شناسه
     */
    public function getMessage(Request $request, string $messageId): JsonResponse
    {
        try {
            $message = $this->alertService->getMessage($messageId);
            
            if (!$message) {
                return response()->json([
                    'success' => false,
                    'message' => 'پیام یافت نشد',
                ], 404);
            }

            $tenderId = $message['tender_id'] ?? null;
            if ($tenderId && !TenderAccessService::canAccess($request, $tenderId)) {
                return $this->ownedTenderNotFoundResponse();
            }

            return response()->json([
                'success' => true,
                'data' => $message,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت تمام پیام‌های تعریف شده (قالب‌های سیستمی — بدون داده مناقصه)
     */
    public function getAllMessages(): JsonResponse
    {
        try {
            $messages = $this->alertService->getAllMessages();

            return response()->json([
                'success' => true,
                'data' => $messages,
                'count' => count($messages),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * علامت‌گذاری پیام به عنوان خوانده شده
     */
    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        try {
            $notification = Notification::find($notificationId);
            
            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'اعلان یافت نشد',
                ], 404);
            }

            if ($notification->tender_id && !TenderAccessService::canAccess($request, $notification->tender_id)) {
                return $this->ownedTenderNotFoundResponse();
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'اعلان به عنوان خوانده شده علامت‌گذاری شد',
                'data' => $notification->fresh()->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت تعداد اعلان‌های خوانده نشده
     */
    public function getUnreadCount(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->requireOwnedTender($request, $tenderId);
            if ($tender instanceof JsonResponse) {
                return $tender;
            }

            $count = Notification::where('tender_id', $tenderId)
                ->where('read', false)
                ->count();

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
}

<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\TenderController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\BidderController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\FormulaController;
use App\Http\Controllers\ManagementReportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StageReportController;
use App\Http\Controllers\ValidationController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\DashboardController;

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminUserController as AdminUsersController;
use App\Http\Controllers\Admin\AdminMessageController;
use App\Http\Controllers\Admin\AdminCmsController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminAuditLogController;
use App\Http\Controllers\Admin\AdminFormulaController;
use App\Http\Controllers\Admin\AdminTenderController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\CmsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health check endpoint
Route::get('/health', [SystemController::class, 'health']);

// Public CMS pages
Route::get('/cms/{slug}', [CmsController::class, 'show']);

if (app()->environment('local') || config('tender.enable_system_routes')) {
    Route::get('/check-database', [SystemController::class, 'checkDatabase'])
        ->middleware(['jwt.auth', 'admin', 'throttle:admin']);
    Route::get('/route-status', [SystemController::class, 'routeStatus'])
        ->middleware(['jwt.auth', 'admin', 'throttle:admin']);
    Route::get('/test-notifications', function () {
        return response()->json([
            'success' => true,
            'message' => 'Notifications route is accessible',
            'routes' => [
                'GET /api/notifications' => 'Get all notifications (requires auth)',
                'GET /api/notifications?limit=5' => 'Get 5 latest notifications (requires auth)',
                'GET /api/notifications/unread-count' => 'Get unread count (requires auth)',
            ]
        ]);
    })->middleware(['jwt.auth', 'admin', 'throttle:admin']);
}

// Clear cache endpoint - supports both GET and POST
// SECURITY: Admin only
Route::match(['get', 'post'], '/clear-cache', [SystemController::class, 'clearCache'])->middleware(['jwt.auth', 'admin', 'throttle:admin']);

Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('jwt.auth');

// Tenders routes - SECURITY: All routes require authentication
// Important: Specific routes must come before parameterized routes
Route::prefix('tenders')->middleware('jwt.auth')->group(function () {
    Route::get('/', [TenderController::class, 'index']);
    Route::post('/', [TenderController::class, 'store']);
    
    // Specific routes (must come before {id} routes)
    Route::post('/{id}/calculate-pb', [TenderController::class, 'calculatePb']);
    Route::post('/{id}/calculate-po', [TenderController::class, 'calculatePo']);
    Route::post('/{id}/calculate-evaluation', [TenderController::class, 'calculateEvaluation']);
    Route::put('/{id}/set-po-calculated-at', [TenderController::class, 'setPoCalculatedAt']);
    Route::put('/{id}/set-evaluation-calculated-at', [TenderController::class, 'setEvaluationCalculatedAt']);
    Route::put('/{id}/invalidate-po', [TenderController::class, 'invalidatePo']);
    Route::put('/{id}/invalidate-evaluation', [TenderController::class, 'invalidateEvaluation']);
    
    // Parameterized routes (must come after specific routes)
    Route::get('/{id}', [TenderController::class, 'show']);
    Route::put('/{id}', [TenderController::class, 'update']);
    Route::delete('/{id}', [TenderController::class, 'destroy']);
});

// Estimates routes - SECURITY: All routes require authentication
Route::prefix('estimates')->middleware('jwt.auth')->group(function () {
    Route::get('/', [EstimateController::class, 'index']);
    Route::get('/tender/{tenderId}', [EstimateController::class, 'getByTenderId']);
    Route::get('/{id}', [EstimateController::class, 'show']);
    Route::post('/', [EstimateController::class, 'store']);
    Route::put('/{id}', [EstimateController::class, 'update']);
    Route::delete('/{id}', [EstimateController::class, 'destroy']);
});

// Indices routes - SECURITY: All routes require authentication
Route::prefix('indices')->middleware('jwt.auth')->group(function () {
    Route::get('/', [IndexController::class, 'index']);
    Route::get('/tender/{tenderId}', [IndexController::class, 'getByTenderId']);
    Route::get('/tender/{tenderId}/type/{type}', [IndexController::class, 'getByTenderIdAndType']);
    Route::get('/{id}', [IndexController::class, 'show']);
    Route::post('/', [IndexController::class, 'store']);
    Route::put('/{id}', [IndexController::class, 'update']);
    Route::delete('/{id}', [IndexController::class, 'destroy']);
    Route::post('/bulk', [IndexController::class, 'bulkUpsert']);
});

// Bidders routes - SECURITY: All routes require authentication
Route::prefix('bidders')->middleware('jwt.auth')->group(function () {
    Route::get('/', [BidderController::class, 'index']);
    Route::get('/tender/{tenderId}', [BidderController::class, 'getByTenderId']);
    Route::get('/{id}/price-items', [BidderController::class, 'getPriceItems']);
    Route::get('/{id}', [BidderController::class, 'show']);
    Route::post('/', [BidderController::class, 'store']);
    Route::put('/{id}', [BidderController::class, 'update']);
    Route::delete('/{id}', [BidderController::class, 'destroy']);
});

// Evaluations routes - SECURITY: All routes require authentication
Route::prefix('evaluations')->middleware('jwt.auth')->group(function () {
    Route::get('/tender/{tenderId}', [EvaluationController::class, 'getByTenderId']);
    Route::get('/{id}', [EvaluationController::class, 'show']);
    Route::post('/', [EvaluationController::class, 'store']);
    Route::put('/{id}', [EvaluationController::class, 'update']);
    Route::delete('/{id}', [EvaluationController::class, 'destroy']);
    Route::post('/bulk', [EvaluationController::class, 'bulkUpsert']);
});

// Users routes - SECURITY: Login is public, others require authentication
Route::prefix('users')->group(function () {
    Route::post('/login', [UserController::class, 'login'])->middleware('throttle:auth'); // Public route
    Route::get('/me', [UserController::class, 'me'])->middleware('jwt.auth');
    Route::get('/', [UserController::class, 'index'])->middleware(['jwt.auth', 'admin']);
    Route::put('/{id}', [UserController::class, 'update'])->middleware('jwt.auth');
    // Admin user creation/reset endpoint (for initial setup) - SECURITY: Admin only
    Route::post('/create-admin', [UserController::class, 'createAdmin'])->middleware(['jwt.auth', 'admin']);
});

if (app()->environment('local') || config('tender.enable_system_routes')) {
    Route::post('/seed', [SystemController::class, 'seed'])->middleware(['jwt.auth', 'admin', 'throttle:admin']);
}

// Messages routes - SECURITY: All routes require authentication
Route::prefix('messages')->middleware('jwt.auth')->group(function () {
    Route::get('/', [MessageController::class, 'index']);
    Route::get('/support-contact', [MessageController::class, 'supportContact']);
    Route::get('/unread-count', [MessageController::class, 'unreadCount']);
    Route::post('/', [MessageController::class, 'store']);
    Route::post('/{id}/hide-conversation', [MessageController::class, 'hideConversation']);
    Route::get('/{id}', [MessageController::class, 'show']);
    Route::put('/{id}', [MessageController::class, 'update']);
});

// Notifications routes - SECURITY: All routes require authentication
Route::prefix('notifications')->middleware('jwt.auth')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/', [NotificationController::class, 'store']);
    Route::get('/{id}', [NotificationController::class, 'show']);
    Route::put('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/', [NotificationController::class, 'clearAll']);
});

// Alert routes (for alert/notification system) - SECURITY: All routes require authentication
Route::prefix('alerts')->middleware('jwt.auth')->group(function () {
    Route::get('/messages', [AlertController::class, 'getAllMessages']);
    Route::get('/messages/{messageId}', [AlertController::class, 'getMessage']);
    Route::get('/tender/{tenderId}', [AlertController::class, 'getTenderMessages']);
    Route::get('/tender/{tenderId}/unread-count', [AlertController::class, 'getUnreadCount']);
    Route::put('/notifications/{notificationId}/read', [AlertController::class, 'markAsRead']);
});

// Validation routes (for comprehensive validation framework) - SECURITY: All routes require authentication
Route::prefix('validation')->middleware('jwt.auth')->group(function () {
    Route::get('/tender/{tenderId}', [ValidationController::class, 'validateTender']);
    Route::get('/tender/{tenderId}/report', [ValidationController::class, 'getReport']);
});

// Formulas routes - SECURITY: Admin only
Route::prefix('formulas')->middleware(['jwt.auth', 'admin'])->group(function () {
    Route::get('/', [FormulaController::class, 'index']);
    Route::get('/{id}', [FormulaController::class, 'show']);
    Route::post('/', [FormulaController::class, 'store']);
    Route::put('/{id}', [FormulaController::class, 'update']);
    Route::delete('/{id}', [FormulaController::class, 'destroy']);
    Route::post('/reset-to-default', [FormulaController::class, 'resetToDefault']);
    Route::post('/seed', [FormulaController::class, 'seed']); // اجرای seeder برای اضافه کردن فرمول‌های جدید
});

// Audit Trail routes (for history and replay) - SECURITY: All routes require authentication
Route::prefix('audit-trail')->middleware('jwt.auth')->group(function () {
    Route::get('/tender/{tenderId}', [AuditTrailController::class, 'getHistory']);
    Route::get('/tender/{tenderId}/summary', [AuditTrailController::class, 'getSummary']);
    Route::get('/tender/{tenderId}/replay', [AuditTrailController::class, 'replayScenario']);
    Route::get('/tender/{tenderId}/inputs', [AuditTrailController::class, 'getInputHistory']);
    Route::get('/tender/{tenderId}/decisions', [AuditTrailController::class, 'getDecisionHistory']);
});

// Stage Report routes (for reports and feedback) - SECURITY: All routes require authentication
Route::prefix('reports')->middleware('jwt.auth')->group(function () {
    Route::get('/tender/{tenderId}/stage', [StageReportController::class, 'getStageReport']);
    Route::get('/tender/{tenderId}/comprehensive', [StageReportController::class, 'getComprehensiveReport']);
    Route::get('/tender/{tenderId}/compliance', [StageReportController::class, 'checkCompliance']);
    
    // Intelligent Text Report routes
    Route::get('/tender/{tenderId}/text', [ReportController::class, 'getTextReport']);
    Route::get('/tender/{tenderId}/markdown', [ReportController::class, 'getMarkdownReport']);
    Route::get('/tender/{tenderId}/html', [ReportController::class, 'getHtmlReport']);
    Route::get('/tender/{tenderId}/plain', [ReportController::class, 'getPlainTextReport']);
    
    // Management Report routes
    Route::get('/management/tender/{tenderId}', [ManagementReportController::class, 'getReport']);
    Route::get('/management/tender/{tenderId}/html', [ManagementReportController::class, 'getHtmlReport']);
    Route::get('/management/tender/{tenderId}/markdown', [ManagementReportController::class, 'getMarkdownReport']);
    Route::get('/management/tender/{tenderId}/text', [ManagementReportController::class, 'getTextReport']);
    Route::get('/management/tender/{tenderId}/pdf', [ManagementReportController::class, 'getPdfReport']);
    Route::get('/management/tender/{tenderId}/word', [ManagementReportController::class, 'getWordReport']);
});

if (app()->environment('local') || config('tender.enable_system_routes')) {
    Route::post('/migrate', [SystemController::class, 'migrate'])->middleware(['jwt.auth', 'admin', 'throttle:admin']);
}

// Admin Panel routes
Route::prefix('admin')->middleware(['jwt.auth', 'admin'])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->middleware('admin.permission:dashboard');
    Route::get('/health', [AdminDashboardController::class, 'health'])->middleware('admin.permission:system');

    Route::prefix('users')->middleware('admin.permission:users')->group(function () {
        Route::get('/permissions', [AdminUsersController::class, 'permissions']);
        Route::get('/', [AdminUsersController::class, 'index']);
        Route::post('/', [AdminUsersController::class, 'store']);
        Route::get('/{id}/view-password', [AdminUsersController::class, 'viewPassword']);
        Route::get('/{id}', [AdminUsersController::class, 'show']);
        Route::put('/{id}', [AdminUsersController::class, 'update']);
        Route::delete('/{id}', [AdminUsersController::class, 'destroy']);
        Route::post('/{id}/reset-password', [AdminUsersController::class, 'resetPassword']);
        Route::post('/{id}/toggle-active', [AdminUsersController::class, 'toggleActive']);
    });

    Route::prefix('messages')->middleware('admin.permission:messages')->group(function () {
        Route::get('/stats', [AdminMessageController::class, 'stats']);
        Route::get('/', [AdminMessageController::class, 'index']);
        Route::post('/conversation/send', [AdminMessageController::class, 'sendConversationMessage']);
        Route::get('/{id}', [AdminMessageController::class, 'show']);
        Route::post('/broadcast', [AdminMessageController::class, 'broadcast']);
        Route::delete('/{id}/conversation', [AdminMessageController::class, 'destroyConversation']);
        Route::post('/{id}/archive-conversation', [AdminMessageController::class, 'archiveConversation']);
        Route::post('/{id}/unarchive-conversation', [AdminMessageController::class, 'unarchiveConversation']);
        Route::post('/{id}/reply', [AdminMessageController::class, 'reply']);
        Route::post('/{id}/close', [AdminMessageController::class, 'close']);
    });

    Route::prefix('cms')->middleware('admin.permission:cms')->group(function () {
        Route::get('/', [AdminCmsController::class, 'index']);
        Route::get('/{slug}', [AdminCmsController::class, 'show']);
        Route::put('/{slug}', [AdminCmsController::class, 'update']);
        Route::get('/{slug}/versions', [AdminCmsController::class, 'versions']);
        Route::post('/{slug}/rollback/{version}', [AdminCmsController::class, 'rollback']);
    });

    Route::prefix('settings')->middleware('admin.permission:settings')->group(function () {
        Route::get('/', [AdminSettingsController::class, 'index']);
        Route::put('/', [AdminSettingsController::class, 'update']);
    });

    Route::prefix('audit-logs')->middleware('admin.permission:audit')->group(function () {
        Route::get('/', [AdminAuditLogController::class, 'index']);
        Route::get('/{id}', [AdminAuditLogController::class, 'show']);
    });

    Route::prefix('formulas')->middleware('admin.permission:formulas')->group(function () {
        Route::get('/{id}/versions', [AdminFormulaController::class, 'versions']);
        Route::post('/{id}/draft', [AdminFormulaController::class, 'saveDraft']);
        Route::post('/{id}/publish', [AdminFormulaController::class, 'publish']);
        Route::post('/{id}/rollback/{version}', [AdminFormulaController::class, 'rollback']);
        Route::post('/test', [AdminFormulaController::class, 'test']);
    });

    Route::prefix('tenders')->middleware('admin.permission:tenders')->group(function () {
        Route::get('/workflow-status', [AdminTenderController::class, 'workflowStatus']);
        Route::get('/', [AdminTenderController::class, 'index']);
        Route::get('/{id}', [AdminTenderController::class, 'show']);
        Route::post('/{id}/archive', [AdminTenderController::class, 'archive']);
        Route::post('/{id}/restore', [AdminTenderController::class, 'restore']);
        Route::post('/{id}/invalidate-po', [AdminTenderController::class, 'invalidatePo']);
        Route::post('/{id}/invalidate-evaluation', [AdminTenderController::class, 'invalidateEvaluation']);
    });

    Route::prefix('analytics')->middleware('admin.permission:analytics')->group(function () {
        Route::get('/', [AdminAnalyticsController::class, 'index']);
        Route::get('/users/{userId}', [AdminAnalyticsController::class, 'userActivity']);
    });
});


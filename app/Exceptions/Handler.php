<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        // Log all exceptions for debugging
        if (config('app.debug')) {
            Log::error('Exception in request', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        // For API requests, return JSON response
        if ($request->is('api/*')) {
            $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            
            // Handle validation exceptions
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $errors = $e->errors();
                $details = collect($errors)->flatten()->filter()->values();
                $message = $details->count() === 1
                    ? $details->first()
                    : ($details->isNotEmpty() ? $details->implode(' | ') : 'خطا در اعتبارسنجی داده‌ها');

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => $errors,
                ], 422);
            }

            // Handle authentication exceptions
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'احراز هویت انجام نشده است',
                ], 401);
            }

            // Handle authorization exceptions
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'دسترسی غیرمجاز',
                ], 403);
            }

            // Handle model not found exceptions
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'منبع مورد نظر یافت نشد',
                ], 404);
            }

            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? 'خطا در سرور: ' . $e->getMessage() 
                    : 'خطا در سرور. لطفاً با مدیر سیستم تماس بگیرید.',
                'error_type' => config('app.debug') ? get_class($e) : null,
                'file' => config('app.debug') ? $e->getFile() . ':' . $e->getLine() : null,
            ], $statusCode);
        }

        return parent::render($request, $e);
    }
}

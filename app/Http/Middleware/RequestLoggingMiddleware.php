<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestLoggingMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        // Log request details (excluding sensitive data)
        $logData = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        // Only log request body for non-sensitive endpoints
        $sensitiveEndpoints = ['login', 'password', 'token', 'auth'];
        $isSensitive = false;
        foreach ($sensitiveEndpoints as $endpoint) {
            if (str_contains($request->path(), $endpoint)) {
                $isSensitive = true;
                break;
            }
        }

        if (!$isSensitive && ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH'))) {
            // SECURITY: Exclude sensitive data from logs
            $logData['payload'] = $request->except([
                'password', 
                'password_confirmation', 
                'token',
                'jwt_secret',
                'authorization', // Don't log Authorization header
            ]);
        }
        
        // SECURITY: Never log Authorization header
        if ($request->hasHeader('Authorization')) {
            $authHeader = $request->header('Authorization');
            // Only log first few characters for debugging, not full token
            if (strlen($authHeader) > 20) {
                $logData['auth_header'] = substr($authHeader, 0, 20) . '...';
            }
        }

        Log::info('API Request', $logData);

        $response = $next($request);

        // Log response time
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::info('API Response', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
        ]);

        return $response;
    }
}


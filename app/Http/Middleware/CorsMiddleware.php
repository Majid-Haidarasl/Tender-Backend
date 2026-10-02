<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = config('tender.frontend_urls', []);

        $origin = $request->header('Origin');

        // Check if origin is allowed
        $isAllowed = false;
        if (!$origin) {
            // Allow requests with no origin (like mobile apps or curl requests)
            $isAllowed = true;
        } elseif (in_array($origin, $allowedOrigins, true)) {
            $isAllowed = true;
        } elseif (app()->environment('local') || config('app.debug')) {
            // In development, allow all origins for easier testing
            $isAllowed = true;
        }

        if (!$isAllowed) {
            return response()->json([
                'success' => false,
                'message' => 'Not allowed by CORS',
            ], 403);
        }

        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 200);
        } else {
            $response = $next($request);
        }

        if ($origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        // Security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // Remove deprecated headers
        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');
        
        // Set Content-Type with charset=utf-8 for all responses
        $contentType = $response->headers->get('Content-Type');
        if ($contentType && !str_contains($contentType, 'charset')) {
            // Add charset to existing Content-Type
            if (str_contains($contentType, 'application/json')) {
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            } elseif (str_contains($contentType, 'text/html')) {
                $response->headers->set('Content-Type', 'text/html; charset=utf-8');
            } elseif (str_contains($contentType, 'text/plain')) {
                $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            }
        } elseif (!$contentType && ($request->expectsJson() || $request->is('api/*'))) {
            // Set Content-Type for JSON responses if not set
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        }
        
        // Set Cache-Control headers
        // For API responses, use appropriate cache control
        if ($request->is('api/*')) {
            if (!$response->headers->has('Cache-Control')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }
        } else {
            // For HTML responses, no cache for security
            if (!$response->headers->has('Cache-Control')) {
                $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            }
        }
        
        // Content Security Policy (CSP) - only for HTML responses, not JSON
        // Note: CSP should be set in HTML meta tag or web server config, not in API responses
        // We only set it for non-API responses to avoid unnecessary headers
        // Note: 'unsafe-eval' is required for some libraries (like MathJax), but should be minimized
        if (!$request->expectsJson() && !$request->is('api/*')) {
            $csp = "default-src 'self'; " .
                   "script-src 'self' 'unsafe-inline'; " .
                   "style-src 'self' 'unsafe-inline'; " .
                   "img-src 'self' data: https:; " .
                   "font-src 'self' data:; " .
                   "connect-src 'self' " . ($origin ?? '*') . ";";
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }
}


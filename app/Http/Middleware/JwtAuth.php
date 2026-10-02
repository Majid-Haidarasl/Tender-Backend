<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class JwtAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $authHeader = $request->header('Authorization');

            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن احراز هویت یافت نشد',
                ], 401);
            }

            $token = substr($authHeader, 7);

            $jwtSecret = config('tender.jwt_secret');
            
            // SECURITY FIX: Don't use default value in production
            if (empty($jwtSecret)) {
                Log::error('JWT_SECRET is not set in environment');
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در پیکربندی سرور',
                ], 500);
            }

            try {
                $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
            } catch (ExpiredException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن منقضی شده است',
                ], 401);
            } catch (SignatureInvalidException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن نامعتبر است',
                ], 401);
            } catch (\Exception $e) {
                Log::warning('JWT decode error', [
                    'error' => $e->getMessage(),
                    'ip' => $request->ip(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'توکن نامعتبر است',
                ], 401);
            }

            // Verify user exists and is active
            $user = User::where('id', $decoded->id)
                ->where('is_active', true)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'کاربر یافت نشد یا غیرفعال است',
                ], 401);
            }

            // Attach user to request for use in controllers
            $request->merge(['user' => $user]);
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            return $next($request);
        } catch (\Exception $e) {
            Log::error('JWT Auth middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در احراز هویت',
            ], 500);
        }
    }
}


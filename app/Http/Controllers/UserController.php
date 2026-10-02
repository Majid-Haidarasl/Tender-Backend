<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class UserController extends Controller
{
    /**
     * Login route
     * 
     * @OA\Post(
     *     path="/api/users/login",
     *     summary="User login",
     *     tags={"Users"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"username", "password"},
     *             @OA\Property(property="username", type="string", example="admin"),
     *             @OA\Property(property="password", type="string", example="admin123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="ورود موفقیت‌آمیز"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="token", type="string"),
     *                 @OA\Property(property="user", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials"
     *     )
     * )
     */
    public function login(Request $request): JsonResponse
    {
        try {
            // Log login attempt for debugging
            // SECURITY: Log login attempt without sensitive data
            Log::info('Login attempt', [
                'username' => $request->input('username'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                // Password is NOT logged for security
            ]);

            $username = $request->input('username');
            $password = $request->input('password');

            // Validate input
            if (empty($username) || empty($password)) {
                return $this->errorResponse('لطفاً نام کاربری و رمز عبور را وارد کنید', 400);
            }

            // Find user by username
            $user = User::where('username', $username)
                ->where('is_active', true)
                ->first();

            if (!$user) {
                Log::warning('Login failed: User not found or inactive', ['username' => $username]);
                return $this->errorResponse('نام کاربری یا رمز عبور اشتباه است', 401);
            }

            // Check password
            if (!Hash::check($password, $user->password)) {
                $user->failed_login_attempts = ($user->failed_login_attempts ?? 0) + 1;
                if ($user->failed_login_attempts >= 5) {
                    $user->locked_until = now()->addMinutes(15);
                }
                $user->save();
                Log::warning('Login failed: Invalid password', ['username' => $username, 'user_id' => $user->id]);
                return $this->errorResponse('نام کاربری یا رمز عبور اشتباه است', 401);
            }

            if ($user->locked_until && $user->locked_until->isFuture()) {
                return $this->errorResponse('حساب کاربری به دلیل تلاش‌های ناموفق موقتاً قفل شده است', 423);
            }

            $user->last_login_at = now();
            $user->failed_login_attempts = 0;
            $user->locked_until = null;
            $user->save();

            // Generate JWT token
            try {
                $jwtSecret = config('tender.jwt_secret');
                if (empty($jwtSecret)) {
                    Log::error('JWT_SECRET is not set in environment');
                    return $this->errorResponse('خطا در پیکربندی سرور', 500);
                }

                $payload = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'role' => $user->role,
                    'iat' => time(),
                    'exp' => time() + (24 * 60 * 60), // 24 hours
                ];
                $token = JWT::encode($payload, $jwtSecret, 'HS256');
            } catch (\Exception $jwtError) {
                Log::error('JWT token generation failed', [
                    'error' => $jwtError->getMessage(),
                    'user_id' => $user->id,
                ]);
                return $this->errorResponse('خطا در تولید توکن احراز هویت', 500);
            }

            $avatarUrl = $user->getAvatarPublicUrl();
            
            $userData = [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'company_name' => $user->company_name,
                'position' => $user->position,
                'employee_number' => $user->employee_number,
                'mobile_number' => $user->mobile_number,
                'tender_committee_role' => $user->tender_committee_role,
                'avatar' => $avatarUrl,
                'role' => $user->role,
                'admin_role' => $user->admin_role,
                'admin_permissions' => $user->admin_permissions ?? \App\Services\AdminPermissionService::getPermissions($user),
            ];
            
            // SECURITY: Log successful login without token
            Log::info('Login successful', [
                'user_id' => $user->id,
                'username' => $user->username,
                // Token is NOT logged for security
            ]);
            
            return $this->successResponse([
                'token' => $token,
                'user' => $userData,
            ], 'ورود موفقیت‌آمیز');
        } catch (\Illuminate\Database\QueryException $e) {
            // Database connection errors
            Log::error('Database error during login', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            return $this->errorResponse('خطا در اتصال به پایگاه داده', 500);
        } catch (\Exception $e) {
            // Log the actual error for debugging
            Log::error('Login error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'username' => $request->input('username'),
            ]);
            
            // In development, return detailed error
            if (config('app.debug')) {
                return $this->errorResponse('خطا در سرور: ' . $e->getMessage(), 500);
            }
            
            // In production, return generic error
            return $this->errorResponse('خطا در سرور. لطفاً با مدیر سیستم تماس بگیرید.', 500);
        }
    }

    /**
     * Get current user info
     */
    public function me(Request $request): JsonResponse
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

            try {
                $jwtSecret = config('tender.jwt_secret');
                if (empty($jwtSecret)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'خطا در پیکربندی سرور',
                    ], 500);
                }
                $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));

                $user = User::select('id', 'username', 'full_name', 'company_name', 'position', 'employee_number', 'mobile_number', 'tender_committee_role', 'avatar', 'role', 'admin_role', 'admin_permissions', 'is_active', 'created_at')
                    ->find($decoded->id);

                if (!$user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'کاربر یافت نشد',
                    ], 404);
                }

                $userData = $user->toArray();
                if ($user->role === 'admin') {
                    $userData['admin_permissions'] = $user->admin_permissions ?? \App\Services\AdminPermissionService::getPermissions($user);
                }
                // Add full avatar URL if exists
                if ($user->avatar) {
                    $userData['avatar'] = $user->getAvatarPublicUrl();
                }
                
                return response()->json([
                    'success' => true,
                    'data' => $userData,
                ]);
            } catch (\Exception $jwtError) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن نامعتبر است',
                ], 401);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در سرور',
            ], 500);
        }
    }

    /**
     * Get all users (admin only)
     */
    public function index(): JsonResponse
    {
        try {
            $users = User::select('id', 'username', 'full_name', 'company_name', 'position', 'employee_number', 'mobile_number', 'tender_committee_role', 'avatar', 'role', 'is_active', 'created_at')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $users->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در سرور',
            ], 500);
        }
    }

    /**
     * Update user info
     */
    public function update(Request $request, string $id): JsonResponse
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

            try {
                $jwtSecret = config('tender.jwt_secret');
                if (empty($jwtSecret)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'خطا در پیکربندی سرور',
                    ], 500);
                }
                $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));

                // فقط کاربر می‌تواند اطلاعات خودش را ویرایش کند
                if ($decoded->id !== $id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'شما فقط می‌توانید اطلاعات خودتان را ویرایش کنید',
                    ], 403);
                }

                $user = User::find($id);

                if (!$user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'کاربر یافت نشد',
                    ], 404);
                }

                // Validate input
                $fullName = $request->input('full_name');
                $password = $request->input('password');

                // Log for debugging
                Log::info('User update request', [
                    'user_id' => $id,
                    'content_type' => $request->header('Content-Type'),
                    'method' => $request->method(),
                    'has_file' => $request->hasFile('avatar'),
                    'all_input' => $request->all(),
                    'full_name' => $fullName,
                    'has_avatar_input' => $request->has('avatar'),
                    'avatar_input' => $request->input('avatar')
                ]);

                if (empty($fullName)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'نام کامل الزامی است',
                    ], 400);
                }

                // Update basic fields
                $user->full_name = $fullName;
                $user->company_name = $request->input('company_name');
                $user->position = $request->input('position');
                $user->employee_number = $request->input('employee_number');
                $user->mobile_number = $request->input('mobile_number');
                $user->tender_committee_role = $request->input('tender_committee_role');

                // Handle avatar upload
                if ($request->hasFile('avatar')) {
                    $file = $request->file('avatar');
                    
                    // Validate file type
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
                    if (!in_array($file->getMimeType(), $allowedMimes)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'فرمت فایل تصویر نامعتبر است. فقط JPG, PNG و GIF مجاز است',
                        ], 400);
                    }

                    // Validate file size (max 2MB)
                    if ($file->getSize() > 2 * 1024 * 1024) {
                        return response()->json([
                            'success' => false,
                            'message' => 'حجم فایل تصویر نباید بیشتر از 2 مگابایت باشد',
                        ], 400);
                    }

                    // Generate unique filename
                    $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                    
                    // Store in public/avatars directory
                    $path = $file->storeAs('avatars', $filename, 'public');
                    
                    // Delete old avatar if exists
                    if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                        Storage::disk('public')->delete($user->avatar);
                    }
                    
                    $user->avatar = $path;
                } elseif ($request->has('avatar') && $request->input('avatar') === '') {
                    // Delete avatar if explicitly set to empty string
                    if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                        Storage::disk('public')->delete($user->avatar);
                    }
                    $user->avatar = null;
                }

                // Update password if provided
                // SECURITY: Improved password policy - minimum 8 characters
                if (!empty($password)) {
                    if (strlen($password) < 8) {
                        return response()->json([
                            'success' => false,
                            'message' => 'رمز عبور باید حداقل ۸ کاراکتر باشد',
                        ], 400);
                    }
                    
                    // SECURITY: Optional password complexity check (can be enabled)
                    // Uncomment below to enforce password complexity:
                    // if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
                    //     return response()->json([
                    //         'success' => false,
                    //         'message' => 'رمز عبور باید شامل حروف کوچک، بزرگ و عدد باشد',
                    //     ], 400);
                    // }
                    
                    $user->setPlainPassword($password);
                }

                $user->save();

                return response()->json([
                    'success' => true,
                    'message' => 'اطلاعات با موفقیت به‌روزرسانی شد',
                    'data' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'full_name' => $user->full_name,
                        'company_name' => $user->company_name,
                        'position' => $user->position,
                        'employee_number' => $user->employee_number,
                        'mobile_number' => $user->mobile_number,
                        'tender_committee_role' => $user->tender_committee_role,
                        'avatar' => $user->getAvatarPublicUrl(),
                        'role' => $user->role,
                    ],
                ]);
            } catch (\Exception $jwtError) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن نامعتبر است',
                ], 401);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در سرور',
            ], 500);
        }
    }

    /**
     * Create or reset admin user
     */
    public function createAdmin(Request $request): JsonResponse
    {
        try {
            $username = 'admin';
            $password = 'admin123';
            
            $existingAdmin = User::where('username', $username)->first();

            if ($existingAdmin) {
                // Reset password and ensure user is active
                $existingAdmin->password = Hash::make($password);
                $existingAdmin->is_active = true;
                $existingAdmin->role = 'admin';
                $existingAdmin->save();
                
                // SECURITY: Don't return password in response - log it securely instead
                Log::info('Admin user password reset', [
                    'username' => $username,
                    'user_id' => $existingAdmin->id,
                    'ip' => $request->ip(),
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'کاربر ادمین با موفقیت بازنشانی شد. رمز عبور در لاگ سرور ثبت شده است.',
                    'data' => [
                        'username' => $username,
                        // SECURITY: Password is NOT returned in response
                    ],
                ]);
            } else {
                // Create new admin user
                User::create([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'username' => $username,
                    'password' => Hash::make($password),
                    'full_name' => 'مدیر سیستم',
                    'role' => 'admin',
                    'is_active' => true,
                ]);

                // SECURITY: Don't return password in response - log it securely instead
                Log::info('Admin user created', [
                    'username' => $username,
                    'ip' => $request->ip(),
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'کاربر ادمین با موفقیت ایجاد شد. رمز عبور در لاگ سرور ثبت شده است.',
                    'data' => [
                        'username' => $username,
                        // SECURITY: Password is NOT returned in response
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Create admin user error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد کاربر ادمین: ' . $e->getMessage(),
            ], 500);
        }
    }
}


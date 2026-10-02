<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\DecisionHistory;
use App\Models\InputHistory;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Tender;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\AdminPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminUserController extends Controller
{
    private const PROTECTED_USERNAMES = ['majidsupp'];
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->query('role')) {
            $query->where('role', $request->query('role'));
        }
        if ($request->query('is_active') !== null) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        $users = $this->sortUsersWithPinnedFirst($query->get())
            ->map(fn ($u) => $this->formatUser($u))
            ->values();

        return $this->successResponse($users);
    }

    public function show(string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->notFoundResponse('کاربر یافت نشد');
        }

        $stats = [
            'tenders_count' => Tender::count(),
            'messages_sent' => \App\Models\Message::where('user_id', $id)->count(),
            'messages_received' => \App\Models\Message::where('recipient_id', $id)->count(),
        ];

        return $this->successResponse([
            'user' => $this->formatUser($user),
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'username' => trim((string) $request->input('username', '')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,username'],
            'password' => 'required|string|min:8|max:255',
            'full_name' => 'required|string|max:255',
            'role' => 'nullable|in:user,admin',
            'admin_role' => 'nullable|required_if:role,admin|in:super_admin,support_admin',
            'admin_permissions' => 'nullable|array',
            'is_active' => 'boolean',
            'company_name' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'mobile_number' => 'nullable|string|max:20',
        ], [
            'username.required' => 'نام کاربری الزامی است',
            'username.min' => 'نام کاربری باید حداقل ۳ کاراکتر باشد',
            'username.regex' => 'نام کاربری باید فقط حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط (_) باشد',
            'username.unique' => 'این نام کاربری قبلاً ثبت شده است',
            'password.required' => 'رمز عبور الزامی است',
            'password.min' => 'رمز عبور باید حداقل ۸ کاراکتر باشد',
            'full_name.required' => 'نام کامل الزامی است',
            'admin_role.required_if' => 'نوع دسترسی مدیر الزامی است',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $admin = $request->user();
        $role = $request->input('role', 'user');

        $user = new User([
            'id' => (string) Str::uuid(),
            'username' => $request->input('username'),
            'full_name' => $request->input('full_name'),
            'company_name' => $request->input('company_name') ?: null,
            'position' => $request->input('position') ?: null,
            'mobile_number' => $request->input('mobile_number') ?: null,
            'role' => $role,
            'admin_role' => $role === 'admin' ? $request->input('admin_role', 'super_admin') : null,
            'admin_permissions' => $role === 'admin' ? $request->input('admin_permissions') : null,
            'is_active' => $request->boolean('is_active', true),
        ]);
        $user->setPlainPassword($request->input('password'));
        $user->save();

        AdminAuditService::log(
            $admin->id, 'user.create', 'user', $user->id,
            null, ['username' => $user->username, 'role' => $user->role],
            "ایجاد کاربر {$user->username}", $request
        );

        return $this->successResponse($this->formatUser($user), 'کاربر با موفقیت ایجاد شد', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->notFoundResponse('کاربر یافت نشد');
        }

        if ($request->has('is_active')) {
            $request->merge(['is_active' => $request->boolean('is_active')]);
        }

        $validator = Validator::make($request->all(), [
            'full_name' => 'sometimes|string|max:255',
            'password' => 'nullable|string|min:8',
            'role' => 'sometimes|in:user,admin',
            'admin_role' => 'nullable|in:super_admin,support_admin',
            'admin_permissions' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'company_name' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'mobile_number' => 'nullable|string|max:20',
        ], [
            'password.min' => 'رمز عبور باید حداقل ۸ کاراکتر باشد',
            'full_name.required' => 'نام کامل الزامی است',
            'admin_role.in' => 'نوع دسترسی مدیر نامعتبر است',
            'role.in' => 'نقش کاربر نامعتبر است',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $admin = $request->user();
        $oldValues = $user->only(['full_name', 'role', 'admin_role', 'is_active']);

        if ($request->has('full_name')) $user->full_name = $request->input('full_name');
        if ($request->has('company_name')) $user->company_name = $request->input('company_name');
        if ($request->has('position')) $user->position = $request->input('position');
        if ($request->has('mobile_number')) $user->mobile_number = $request->input('mobile_number');
        if ($request->has('role')) {
            $user->role = $request->input('role');
            if ($user->role !== 'admin') {
                $user->admin_role = null;
                $user->admin_permissions = null;
            }
        }
        if ($request->has('admin_role') && $user->role === 'admin') {
            $user->admin_role = $request->input('admin_role');
        }
        if ($request->has('admin_permissions') && $user->role === 'admin') {
            $user->admin_permissions = $request->input('admin_permissions');
        }
        if ($request->has('is_active')) $user->is_active = $request->boolean('is_active');
        if ($request->filled('password')) {
            $user->setPlainPassword($request->input('password'));
        }

        $user->save();

        AdminAuditService::log(
            $admin->id, 'user.update', 'user', $user->id,
            $oldValues, $user->only(['full_name', 'role', 'admin_role', 'is_active']),
            "ویرایش کاربر {$user->username}", $request
        );

        return $this->successResponse($this->formatUser($user), 'کاربر با موفقیت به‌روزرسانی شد');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->notFoundResponse('کاربر یافت نشد');
        }

        if ($user->id === $request->user()->id) {
            return $this->errorResponse('نمی‌توانید حساب خود را حذف کنید', 400);
        }

        if (in_array($user->username, self::PROTECTED_USERNAMES, true)) {
            return $this->errorResponse('این کاربر سیستمی است و قابل حذف نیست', 403);
        }

        $username = $user->username;
        $userId = $user->id;

        DB::transaction(function () use ($user) {
            Message::where('user_id', $user->id)
                ->orWhere('recipient_id', $user->id)
                ->orWhere('replied_by', $user->id)
                ->delete();

            Notification::where('user_id', $user->id)->delete();
            AuditTrail::where('user_id', $user->id)->delete();
            InputHistory::where('user_id', $user->id)->delete();
            DecisionHistory::where('user_id', $user->id)->delete();

            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $user->delete();
        });

        AdminAuditService::log(
            $request->user()->id, 'user.delete', 'user', $userId,
            ['username' => $username], null,
            "حذف کامل کاربر {$username} و داده‌های مرتبط", $request
        );

        return $this->successResponse(null, 'کاربر و تمام اطلاعات مرتبط با موفقیت حذف شد');
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->notFoundResponse('کاربر یافت نشد');
        }

        $password = $request->input('password', Str::random(12));
        if (strlen($password) < 8) {
            return $this->errorResponse('رمز عبور باید حداقل ۸ کاراکتر باشد', 400);
        }

        $user->setPlainPassword($password);
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();

        AdminAuditService::log(
            $request->user()->id, 'user.reset_password', 'user', $user->id,
            null, null, "بازنشانی رمز عبور {$user->username}", $request
        );

        return $this->successResponse(['username' => $user->username], 'رمز عبور با موفقیت بازنشانی شد');
    }

    public function viewPassword(Request $request, string $id): JsonResponse
    {
        if (!AdminPermissionService::isSuperAdmin($request->user())) {
            return $this->errorResponse('فقط مدیر ارشد می‌تواند رمز عبور کاربران را مشاهده کند', 403);
        }

        $user = User::find($id);
        if (!$user) {
            return $this->notFoundResponse('کاربر یافت نشد');
        }

        AdminAuditService::log(
            $request->user()->id, 'user.view_password', 'user', $user->id,
            null, null, "مشاهده رمز عبور کاربر {$user->username}", $request
        );

        return $this->successResponse([
            'username' => $user->username,
            'password' => $user->viewable_password,
            'available' => filled($user->viewable_password),
        ]);
    }

    public function toggleActive(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->notFoundResponse('کاربر یافت نشد');
        }

        if ($user->id === $request->user()->id) {
            return $this->errorResponse('نمی‌توانید حساب خود را غیرفعال کنید', 400);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        AdminAuditService::log(
            $request->user()->id, 'user.toggle_active', 'user', $user->id,
            null, ['is_active' => $user->is_active],
            ($user->is_active ? 'فعال‌سازی' : 'غیرفعال‌سازی') . " کاربر {$user->username}", $request
        );

        return $this->successResponse($this->formatUser($user));
    }

    public function permissions(): JsonResponse
    {
        return $this->successResponse([
            'permissions' => AdminPermissionService::PERMISSIONS,
            'roles' => AdminPermissionService::ROLE_PERMISSIONS,
        ]);
    }

    private function sortUsersWithPinnedFirst($users)
    {
        return $users->sort(function (User $a, User $b) {
            $aPinned = array_search($a->username, self::PROTECTED_USERNAMES, true);
            $bPinned = array_search($b->username, self::PROTECTED_USERNAMES, true);

            if ($aPinned !== false && $bPinned !== false) {
                return $aPinned <=> $bPinned;
            }
            if ($aPinned !== false) {
                return -1;
            }
            if ($bPinned !== false) {
                return 1;
            }

            return $b->created_at <=> $a->created_at;
        });
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'company_name' => $user->company_name,
            'position' => $user->position,
            'mobile_number' => $user->mobile_number,
            'role' => $user->role,
            'admin_role' => $user->admin_role,
            'admin_permissions' => $user->role === 'admin'
                ? ($user->admin_permissions ?? AdminPermissionService::getPermissions($user))
                : [],
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
        ];
    }
}

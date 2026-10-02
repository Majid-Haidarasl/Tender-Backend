<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasUuids, Notifiable;

    protected $table = 'users';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'username',
        'password',
        'viewable_password',
        'full_name',
        'company_name',
        'position',
        'employee_number',
        'mobile_number',
        'tender_committee_role',
        'avatar',
        'role',
        'admin_role',
        'admin_permissions',
        'is_active',
        'last_login_at',
        'failed_login_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'password',
        'viewable_password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'admin_permissions' => 'array',
        'viewable_password' => 'encrypted',
        'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'role' => 'user',
        'is_active' => true,
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function setPlainPassword(string $plainPassword): void
    {
        $this->password = Hash::make($plainPassword);
        $this->viewable_password = $plainPassword;
    }

    public function getAvatarPublicUrl(): ?string
    {
        $path = $this->attributes['avatar'] ?? null;
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (!Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        if (array_key_exists('avatar', $array)) {
            $array['avatar'] = $this->getAvatarPublicUrl();
        }

        return $array;
    }
}


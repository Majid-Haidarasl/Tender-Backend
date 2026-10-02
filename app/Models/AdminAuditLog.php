<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminAuditLog extends Model
{
    use HasUuids;

    protected $table = 'admin_audit_logs';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'admin_id', 'action', 'target_type', 'target_id',
        'old_values', 'new_values', 'ip_address', 'description',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}

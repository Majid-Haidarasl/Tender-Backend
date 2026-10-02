<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SystemSetting extends Model
{
    use HasUuids;

    protected $table = 'system_settings';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'key', 'value', 'group', 'description', 'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

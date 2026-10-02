<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CmsPage extends Model
{
    use HasUuids;

    protected $table = 'cms_pages';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'slug', 'title', 'content', 'metadata',
        'version', 'is_published', 'updated_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_published' => 'boolean',
        'version' => 'integer',
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

    public function versions(): HasMany
    {
        return $this->hasMany(CmsPageVersion::class)->orderByDesc('version');
    }
}

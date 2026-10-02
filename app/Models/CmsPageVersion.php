<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CmsPageVersion extends Model
{
    use HasUuids;

    protected $table = 'cms_page_versions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'cms_page_id', 'version', 'title', 'content',
        'metadata', 'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
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

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'cms_page_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

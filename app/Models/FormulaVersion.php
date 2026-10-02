<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FormulaVersion extends Model
{
    use HasUuids;

    protected $table = 'formula_versions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'formula_id', 'version', 'snapshot', 'created_by', 'change_note',
    ];

    protected $casts = [
        'snapshot' => 'array',
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

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

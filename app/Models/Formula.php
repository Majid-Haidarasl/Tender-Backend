<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\FormulaService;

class Formula extends Model
{
    use HasUuids;

    protected $table = 'formulas';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'category',
        'description',
        'formula',
        'parameters',
        'conditions',
        'is_active',
        'is_default',
        'status',
        'draft_data',
        'version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'parameters' => 'array',
        'conditions' => 'array',
        'draft_data' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'version' => 1,
    ];

    /**
     * Relationship with User (creator)
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relationship with User (updater)
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get active formula by name
     */
    public static function getActiveByName(string $name): ?self
    {
        return static::where('name', $name)
            ->where('is_active', true)
            ->orderBy('version', 'desc')
            ->first();
    }

    /**
     * Get all active formulas by category
     */
    public static function getActiveByCategory(string $category): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('category', $category)
            ->where('is_active', true)
            ->orderBy('version', 'desc')
            ->get();
    }

    /**
     * Evaluate formula with given variables
     */
    public function evaluate(array $variables = []): float
    {
        // استفاده از FormulaService برای ارزیابی امن
        return FormulaService::evaluateFormula($this->formula, $variables);
    }
}


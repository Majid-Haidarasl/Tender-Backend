<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class Tender extends Model
{
    use HasUuids;

    protected $table = 'tenders';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'code',
        'title',
        'owner_name',
        'type',
        'date',
        'is_two_stage',
        'normalize_prices',
        'is_adjustable',
        'base_period',
        'po_method',
        'tgamma',
        'tbeta',
        'delta',
        'a_max',
        'normalization_factor',
        'description',
        'status',
        'pb',
        'po',
        'po_calculated_at',
        'po_input_hash',
        'evaluation_calculated_at',
        'evaluation_input_hash',
    ];

    protected $casts = [
        'is_two_stage' => 'boolean',
        'normalize_prices' => 'boolean',
        'is_adjustable' => 'boolean',
        'tgamma' => 'decimal:4',
        'tbeta' => 'decimal:4',
        'delta' => 'decimal:4',
        'a_max' => 'decimal:2',
        'normalization_factor' => 'decimal:4',
        'pb' => 'decimal:2',
        'po' => 'decimal:2',
        'po_calculated_at' => 'datetime',
        'evaluation_calculated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'is_two_stage' => false,
        'normalize_prices' => false,
        'is_adjustable' => false,
        'po_method' => '1',
        'tgamma' => 1.0,
        'tbeta' => 0.5,
        'delta' => 0,
        'status' => 'active',
        'pb' => 0,
        'po' => 0,
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

    /**
     * Relationship with User (owner)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the estimates for the tender.
     */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class, 'tender_id');
    }

    /**
     * Get the indices for the tender.
     */
    public function indices(): HasMany
    {
        return $this->hasMany(Index::class, 'tender_id');
    }

    /**
     * Get the bidders for the tender.
     */
    public function bidders(): HasMany
    {
        return $this->hasMany(Bidder::class, 'tender_id');
    }

    /**
     * Get the evaluation results for the tender.
     */
    public function evaluationResults(): HasMany
    {
        return $this->hasMany(EvaluationResult::class, 'tender_id');
    }

    /**
     * Add validity flags (po_is_valid, evaluation_is_valid) to the model.
     */
    public function getPoIsValidAttribute(): bool
    {
        if (!Schema::hasColumn('tenders', 'po_calculated_at')) {
            return $this->po > 0; // اگر ستون وجود ندارد، فقط po > 0 را بررسی می‌کنیم
        }
        return $this->po_calculated_at !== null && $this->po > 0;
    }

    public function getEvaluationIsValidAttribute(): bool
    {
        if (!Schema::hasColumn('tenders', 'evaluation_calculated_at')) {
            return false; // اگر ستون وجود ندارد، false برمی‌گرداند
        }
        return $this->evaluation_calculated_at !== null;
    }

    /**
     * Append validity flags to JSON.
     * Note: این flags فقط زمانی append می‌شوند که explicitly درخواست شوند
     */
    protected $appends = [];
    
    /**
     * Get appends based on context (برای بهبود عملکرد)
     */
    public function getAppends(): array
    {
        return $this->appends;
    }
    
    /**
     * Enable appends for specific use cases
     */
    public function withValidityFlags(): self
    {
        $this->appends = ['po_is_valid', 'evaluation_is_valid'];
        return $this;
    }

    /**
     * Get all tenders with optional filters.
     */
    public static function getAll(array $filters = []): array
    {
        $query = self::query();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $query->orderBy('created_at', 'desc');

        return $query->get()->toArray();
    }

    /**
     * Get tender by code.
     */
    public static function getByCode(string $code, ?string $userId = null): ?self
    {
        $query = self::where('code', $code);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->first();
    }

    /**
     * Invalidate Po calculation (when input data changes).
     */
    public static function invalidatePo(string $tenderId): bool
    {
        $updateData = ['po' => 0];
        
        if (Schema::hasColumn('tenders', 'po_calculated_at')) {
            $updateData['po_calculated_at'] = null;
        }
        if (Schema::hasColumn('tenders', 'po_input_hash')) {
            $updateData['po_input_hash'] = null;
        }
        
        return self::where('id', $tenderId)->update($updateData) > 0;
    }

    /**
     * Invalidate evaluation results (when input data changes).
     */
    public static function invalidateEvaluation(string $tenderId): bool
    {
        $updateData = [];
        
        if (Schema::hasColumn('tenders', 'evaluation_calculated_at')) {
            $updateData['evaluation_calculated_at'] = null;
        }
        if (Schema::hasColumn('tenders', 'evaluation_input_hash')) {
            $updateData['evaluation_input_hash'] = null;
        }
        
        if (empty($updateData)) {
            return true; // اگر ستون‌ها وجود ندارند، true برمی‌گرداند
        }
        
        return self::where('id', $tenderId)->update($updateData) > 0;
    }

    /**
     * Set Po calculation timestamp.
     */
    public static function setPoCalculatedAt(string $tenderId): bool
    {
        if (!Schema::hasColumn('tenders', 'po_calculated_at')) {
            return true; // اگر ستون وجود ندارد، true برمی‌گرداند
        }
        
        return self::where('id', $tenderId)->update([
            'po_calculated_at' => now(),
        ]) > 0;
    }

    /**
     * Set evaluation calculation timestamp.
     */
    public static function setEvaluationCalculatedAt(string $tenderId): bool
    {
        if (!Schema::hasColumn('tenders', 'evaluation_calculated_at')) {
            return true; // اگر ستون وجود ندارد، true برمی‌گرداند
        }
        
        return self::where('id', $tenderId)->update([
            'evaluation_calculated_at' => now(),
        ]) > 0;
    }
}


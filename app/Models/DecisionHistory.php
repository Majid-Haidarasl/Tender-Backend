<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionHistory extends Model
{
    use HasUuids;

    protected $table = 'decision_history';
    protected $keyType = 'string';
    public $incrementing = false;

    // انواع تصمیم
    const DECISION_PATH_SELECTION = 'PATH_SELECTION';
    const DECISION_PRICE_ADJUSTMENT = 'PRICE_ADJUSTMENT';
    const DECISION_BID_REJECTION = 'BID_REJECTION';
    const DECISION_WINNER_SELECTION = 'WINNER_SELECTION';
    const DECISION_SUSPENSION = 'SUSPENSION';
    const DECISION_NORMALIZATION = 'NORMALIZATION';

    // مسیرهای تصمیم
    const PATH_SIMPLE = 'SIMPLE';
    const PATH_STATISTICAL = 'STATISTICAL';
    const PATH_SUSPENDED = 'SUSPENDED';

    protected $fillable = [
        'id',
        'tender_id',
        'stage',
        'decision_path',
        'decision_type',
        'decision_reason',
        'input_data',
        'calculation_data',
        'result_data',
        'conditions_met',
        'conditions_failed',
        'message_id',
        'priority',
        'blocks_process',
        'user_id',
    ];

    protected $casts = [
        'input_data' => 'array',
        'calculation_data' => 'array',
        'result_data' => 'array',
        'conditions_met' => 'array',
        'conditions_failed' => 'array',
        'blocks_process' => 'boolean',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationship with Tender
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }

    /**
     * Relationship with User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * دریافت تاریخچه تصمیم‌گیری یک مناقصه
     */
    public static function getByTenderId(string $tenderId, ?string $decisionPath = null): array
    {
        // بررسی وجود جدول
        try {
            if (!\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('decision_history')) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }
        
        $query = static::where('tender_id', $tenderId)->orderBy('created_at', 'asc');
        
        if ($decisionPath) {
            $query->where('decision_path', $decisionPath);
        }

        return $query->get()->toArray();
    }

    /**
     * دریافت آخرین تصمیم یک مرحله
     */
    public static function getLatestByStage(string $tenderId, string $stage): ?self
    {
        // بررسی وجود جدول
        try {
            if (!\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('decision_history')) {
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }
        
        return static::where('tender_id', $tenderId)
            ->where('stage', $stage)
            ->orderBy('created_at', 'desc')
            ->first();
    }
}


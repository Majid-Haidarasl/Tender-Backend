<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditTrail extends Model
{
    use HasUuids;

    protected $table = 'audit_trails';
    protected $keyType = 'string';
    public $incrementing = false;

    // انواع عمل
    const ACTION_INPUT = 'INPUT';
    const ACTION_CALCULATION = 'CALCULATION';
    const ACTION_DECISION = 'DECISION';
    const ACTION_ERROR = 'ERROR';
    const ACTION_WARNING = 'WARNING';
    const ACTION_INFO = 'INFO';

    // انواع موجودیت
    const ENTITY_TENDER = 'TENDER';
    const ENTITY_ESTIMATE = 'ESTIMATE';
    const ENTITY_INDEX = 'INDEX';
    const ENTITY_BIDDER = 'BIDDER';
    const ENTITY_PO = 'PO';
    const ENTITY_EVALUATION = 'EVALUATION';

    protected $fillable = [
        'id',
        'tender_id',
        'user_id',
        'action_type',
        'entity_type',
        'entity_id',
        'stage',
        'decision_path',
        'description',
        'old_values',
        'new_values',
        'calculated_values',
        'context',
        'message_id',
        'message_type',
        'priority',
        'blocks_process',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'calculated_values' => 'array',
        'context' => 'array',
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
     * دریافت تاریخچه یک مناقصه
     */
    public static function getByTenderId(string $tenderId, ?string $actionType = null, ?int $limit = null): array
    {
        // بررسی وجود جدول
        try {
            if (!\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('audit_trails')) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }
        
        $query = static::where('tender_id', $tenderId)->orderBy('created_at', 'asc');
        
        if ($actionType) {
            $query->where('action_type', $actionType);
        }

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()->toArray();
    }

    /**
     * دریافت تاریخچه یک مرحله
     */
    public static function getByStage(string $tenderId, string $stage): array
    {
        // بررسی وجود جدول
        try {
            if (!\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('audit_trails')) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }
        
        return static::where('tender_id', $tenderId)
            ->where('stage', $stage)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * دریافت تاریخچه مسیر تصمیم
     */
    public static function getByDecisionPath(string $tenderId, string $decisionPath): array
    {
        // بررسی وجود جدول
        try {
            if (!\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('audit_trails')) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }
        
        return static::where('tender_id', $tenderId)
            ->where('decision_path', $decisionPath)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }
}


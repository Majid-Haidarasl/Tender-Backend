<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class InputHistory extends Model
{
    use HasUuids;

    protected $table = 'input_history';
    protected $keyType = 'string';
    public $incrementing = false;

    // انواع ورودی
    const INPUT_PB = 'PB';
    const INPUT_PO = 'PO';
    const INPUT_PI = 'PI';
    const INPUT_INDEX = 'INDEX';
    const INPUT_BETA = 'BETA';
    const INPUT_GAMMA = 'GAMMA';
    const INPUT_F1 = 'F1';
    const INPUT_F2 = 'F2';
    const INPUT_F_PRIME_2 = 'F_PRIME_2';
    const INPUT_F_PRIME_3 = 'F_PRIME_3';

    // انواع موجودیت
    const ENTITY_TENDER = 'TENDER';
    const ENTITY_ESTIMATE = 'ESTIMATE';
    const ENTITY_INDEX = 'INDEX';
    const ENTITY_BIDDER = 'BIDDER';

    // وضعیت‌ها
    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_MODIFIED = 'MODIFIED';

    protected $fillable = [
        'id',
        'tender_id',
        'user_id',
        'input_type',
        'input_name',
        'entity_type',
        'entity_id',
        'original_value',
        'modified_value',
        'normalized_value',
        'status',
        'notes',
        'validation_result',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'original_value' => 'decimal:2',
        'modified_value' => 'decimal:2',
        'normalized_value' => 'decimal:8',
        'validation_result' => 'array',
        'approved_at' => 'datetime',
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
     * دریافت تاریخچه ورودی‌های یک مناقصه
     */
    public static function getByTenderId(string $tenderId, ?string $inputType = null): array
    {
        // بررسی وجود جدول
        try {
            if (!DB::getSchemaBuilder()->hasTable('input_history')) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }
        
        $query = static::where('tender_id', $tenderId)->orderBy('created_at', 'asc');
        
        if ($inputType) {
            $query->where('input_type', $inputType);
        }

        return $query->get()->toArray();
    }

    /**
     * دریافت آخرین مقدار یک ورودی
     */
    public static function getLatestValue(string $tenderId, string $inputType, ?string $entityId = null): ?float
    {
        $query = static::where('tender_id', $tenderId)
            ->where('input_type', $inputType)
            ->where('status', '!=', self::STATUS_REJECTED)
            ->orderBy('created_at', 'desc');

        if ($entityId) {
            $query->where('entity_id', $entityId);
        }

        $latest = $query->first();
        
        if ($latest) {
            return floatval($latest->normalized_value ?? $latest->modified_value ?? $latest->original_value ?? 0);
        }

        return null;
    }
}


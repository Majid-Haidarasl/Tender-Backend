<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvaluationResult extends Model
{
    use HasUuids;

    protected $table = 'evaluation_results';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'tender_id',
        'bidder_id',
        'normalized_price',
        'technical_score',
        'final_score',
        'rank',
        'is_winner',
        'is_winner_first',
        'is_winner_second',
        'notes',
    ];

    protected $casts = [
        'normalized_price' => 'decimal:2',
        'technical_score' => 'decimal:2',
        'final_score' => 'decimal:2',
        'rank' => 'integer',
        'is_winner' => 'boolean',
        'is_winner_first' => 'boolean',
        'is_winner_second' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'is_winner' => false,
        'is_winner_first' => false,
        'is_winner_second' => false,
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
     * Get the tender that owns the evaluation.
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }

    /**
     * Get the bidder that owns the evaluation.
     */
    public function bidder(): BelongsTo
    {
        return $this->belongsTo(Bidder::class, 'bidder_id');
    }

    /**
     * Get all evaluation results for a tender with bidder info.
     */
    public static function getByTenderId(string $tenderId): array
    {
        return self::select('evaluation_results.*', 'bidders.name as bidder_name', 'bidders.price as bidder_price')
            ->leftJoin('bidders', 'evaluation_results.bidder_id', '=', 'bidders.id')
            ->where('evaluation_results.tender_id', $tenderId)
            ->orderBy('evaluation_results.rank', 'asc')
            ->orderBy('evaluation_results.final_score', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get evaluation by ID with bidder info.
     */
    public static function getByIdWithBidder(string $id): ?array
    {
        $result = self::select('evaluation_results.*', 'bidders.name as bidder_name', 'bidders.price as bidder_price')
            ->leftJoin('bidders', 'evaluation_results.bidder_id', '=', 'bidders.id')
            ->where('evaluation_results.id', $id)
            ->first();

        return $result ? $result->toArray() : null;
    }

    /**
     * Get evaluation by tender and bidder.
     */
    public static function getByTenderAndBidder(string $tenderId, string $bidderId): ?self
    {
        return self::where('tender_id', $tenderId)
            ->where('bidder_id', $bidderId)
            ->first();
    }

    /**
     * Delete all evaluations for a tender.
     */
    public static function deleteByTenderId(string $tenderId): bool
    {
        return self::where('tender_id', $tenderId)->delete() > 0;
    }

    /**
     * Bulk create/update evaluation results.
     */
    public static function bulkUpsert(array $evaluationsData): bool
    {
        DB::beginTransaction();

        try {
            // محدودیت برای DECIMAL(20, 2)
            $maxValue = 999999999999999.99;
            $minValue = -999999999999999.99;

            foreach ($evaluationsData as $evalData) {
                $tenderId = $evalData['tender_id'];
                $bidderId = $evalData['bidder_id'];
                $normalizedPrice = $evalData['normalized_price'] ?? null;
                $technicalScore = $evalData['technical_score'] ?? null;
                $finalScore = $evalData['final_score'] ?? null;
                $rank = $evalData['rank'] ?? null;
                $isWinner = $evalData['is_winner'] ?? false;
                $isWinnerFirst = $evalData['is_winner_first'] ?? false;
                $isWinnerSecond = $evalData['is_winner_second'] ?? false;
                $notes = $evalData['notes'] ?? '';

                // Validate and limit final_score to prevent overflow
                $safeFinalScore = self::validateDecimalValue($finalScore, $maxValue, $minValue);
                $safeNormalizedPrice = self::validateDecimalValue($normalizedPrice, $maxValue, $minValue);
                
                // اعتبارسنجی technical_score (DECIMAL(5, 2))
                $safeTechnicalScore = null;
                if ($technicalScore !== null) {
                    $safeTechnicalScore = floatval($technicalScore);
                    if (!is_finite($safeTechnicalScore)) {
                        $safeTechnicalScore = null;
                    } else {
                        $safeTechnicalScore = round($safeTechnicalScore, 2);
                        if ($safeTechnicalScore > 999.99) $safeTechnicalScore = 999.99;
                        if ($safeTechnicalScore < -999.99) $safeTechnicalScore = -999.99;
                    }
                }

                // Check if exists
                $existing = self::where('tender_id', $tenderId)
                    ->where('bidder_id', $bidderId)
                    ->first();

                if ($existing) {
                    // UPDATE شامل همه فیلدها از جمله is_winner_first و is_winner_second
                    $existing->update([
                        'normalized_price' => $safeNormalizedPrice,
                        'technical_score' => $safeTechnicalScore,
                        'final_score' => $safeFinalScore,
                        'rank' => $rank,
                        'is_winner' => $isWinner,
                        'is_winner_first' => $isWinnerFirst,
                        'is_winner_second' => $isWinnerSecond,
                        'notes' => $notes,
                    ]);
                } else {
                    // INSERT دقیقاً مثل Node.js: فقط فیلدهای اصلی
                    // is_winner_first و is_winner_second از default دیتابیس استفاده می‌کنند
                    self::create([
                        'tender_id' => $tenderId,
                        'bidder_id' => $bidderId,
                        'normalized_price' => $safeNormalizedPrice,
                        'technical_score' => $safeTechnicalScore,
                        'final_score' => $safeFinalScore,
                        'rank' => $rank,
                        'is_winner' => $isWinner,
                        'notes' => $notes,
                    ]);
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("Error bulk upserting evaluations: " . $e->getMessage());
        }
    }

    /**
     * Validate and limit decimal value to prevent overflow.
     */
    private static function validateDecimalValue($value, float $maxValue, float $minValue): ?float
    {
        if ($value === null) {
            return null;
        }

        $numValue = floatval($value);

        if (!is_finite($numValue)) {
            return 0;
        }

        if ($numValue > $maxValue) {
            $numValue = $maxValue;
        }

        if ($numValue < $minValue) {
            $numValue = $minValue;
        }

        return round($numValue, 2);
    }
}


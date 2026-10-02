<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Estimate extends Model
{
    use HasUuids;

    protected $table = 'estimates';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'tender_id',
        'section',
        'amount',
        'currency',
        'currency_name',
        'exchange_rate',
        'amount_in_rials',
        'is_adjustable',
        'calculation_method',
        'base_period',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'amount_in_rials' => 'decimal:2',
        'is_adjustable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'IRR',
        'is_adjustable' => false,
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
            
            // اعتبارسنجی amount
            if (isset($model->amount)) {
                $amount = floatval($model->amount);
                if ($amount <= 0 || is_nan($amount) || is_infinite($amount)) {
                    throw new \Exception('مبلغ باید یک عدد معتبر و مثبت باشد');
                }
            }
            
            // اعتبارسنجی exchange_rate
            if (isset($model->exchange_rate) && $model->exchange_rate !== null) {
                $exchangeRate = floatval($model->exchange_rate);
                if ($exchangeRate <= 0 || is_nan($exchangeRate) || is_infinite($exchangeRate)) {
                    throw new \Exception('نرخ تسعیر باید یک عدد معتبر و مثبت باشد');
                }
            }
            
            // محاسبه amount_in_rials اگر خالی است
            if (empty($model->amount_in_rials)) {
                $amount = floatval($model->amount ?? 0);
                $currency = $model->currency ?? 'IRR';
                $exchangeRate = $model->exchange_rate ?? null;
                
                if ($currency === 'IRR' || !$exchangeRate) {
                    $model->amount_in_rials = $amount;
                } else {
                    $exchangeRateValue = floatval($exchangeRate);
                    $model->amount_in_rials = $amount * $exchangeRateValue;
                }
            }
            
            // اعتبارسنجی amount_in_rials
            if (isset($model->amount_in_rials)) {
                $amountInRials = floatval($model->amount_in_rials);
                if ($amountInRials <= 0 || is_nan($amountInRials) || is_infinite($amountInRials)) {
                    throw new \Exception('مبلغ به ریال باید یک عدد معتبر و مثبت باشد');
                }
            }
        });
        
        static::updating(function ($model) {
            // اعتبارسنجی amount
            if ($model->isDirty('amount')) {
                $amount = floatval($model->amount);
                if ($amount <= 0 || is_nan($amount) || is_infinite($amount)) {
                    throw new \Exception('مبلغ باید یک عدد معتبر و مثبت باشد');
                }
            }
            
            // اعتبارسنجی exchange_rate
            if ($model->isDirty('exchange_rate') && $model->exchange_rate !== null) {
                $exchangeRate = floatval($model->exchange_rate);
                if ($exchangeRate <= 0 || is_nan($exchangeRate) || is_infinite($exchangeRate)) {
                    throw new \Exception('نرخ تسعیر باید یک عدد معتبر و مثبت باشد');
                }
            }
            
            // محاسبه مجدد amount_in_rials اگر amount یا exchange_rate تغییر کرده
            if ($model->isDirty('amount') || $model->isDirty('exchange_rate') || $model->isDirty('currency')) {
                $amount = floatval($model->amount ?? $model->getOriginal('amount'));
                $currency = $model->currency ?? $model->getOriginal('currency') ?? 'IRR';
                $exchangeRate = $model->exchange_rate ?? $model->getOriginal('exchange_rate');
                
                if ($currency === 'IRR' || !$exchangeRate) {
                    $model->amount_in_rials = $amount;
                } else {
                    $exchangeRateValue = floatval($exchangeRate);
                    $model->amount_in_rials = $amount * $exchangeRateValue;
                }
            }
            
            // اعتبارسنجی amount_in_rials
            if ($model->isDirty('amount_in_rials')) {
                $amountInRials = floatval($model->amount_in_rials);
                if ($amountInRials <= 0 || is_nan($amountInRials) || is_infinite($amountInRials)) {
                    throw new \Exception('مبلغ به ریال باید یک عدد معتبر و مثبت باشد');
                }
            }
        });
    }

    /**
     * Get the tender that owns the estimate.
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }

    /**
     * Get all estimates for a tender.
     */
    public static function getByTenderId(string $tenderId): array
    {
        return self::where('tender_id', $tenderId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Calculate total Pb for a tender.
     * برای اطمینان از دقت، از CAST استفاده می‌کنیم
     */
    public static function calculateTotalPb(string $tenderId): float
    {
        // استفاده از CAST برای اطمینان از تبدیل صحیح string به decimal
        $sum = self::where('tender_id', $tenderId)
            ->sum(\DB::raw('CAST(amount_in_rials AS DECIMAL(20,2))'));
        
        // اطمینان از اینکه نتیجه معتبر است
        $result = (float) $sum;
        if (is_nan($result) || is_infinite($result) || $result < 0) {
            return 0.0;
        }
        
        return $result;
    }
}


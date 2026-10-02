<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BidderPriceItem extends Model
{
    use HasUuids;

    protected $table = 'bidder_price_items';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'bidder_id',
        'amount',
        'currency',
        'currency_name',
        'exchange_rate',
        'amount_in_rials',
        'is_adjustable',
        'description',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'amount_in_rials' => 'decimal:2',
        'is_adjustable' => 'boolean',
        'created_at' => 'datetime',
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
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Get the bidder that owns the price item.
     */
    public function bidder(): BelongsTo
    {
        return $this->belongsTo(Bidder::class, 'bidder_id');
    }
}


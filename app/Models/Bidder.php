<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Bidder extends Model
{
    use HasUuids;

    protected $table = 'bidders';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'tender_id',
        'name',
        'price',
        'technical_score',
        'notes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'technical_score' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
     * Get the tender that owns the bidder.
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }

    /**
     * Get the price items for the bidder.
     */
    public function priceItems(): HasMany
    {
        return $this->hasMany(BidderPriceItem::class, 'bidder_id');
    }

    /**
     * Get all bidders for a tender with price items.
     */
    public static function getByTenderId(string $tenderId): array
    {
        $bidders = self::where('tender_id', $tenderId)
            ->orderBy('price', 'asc')
            ->get();

        $result = [];
        foreach ($bidders as $bidder) {
            $bidderArray = $bidder->toArray();
            $bidderArray['price_items'] = BidderPriceItem::where('bidder_id', $bidder->id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->toArray();
            $bidderArray['price_items_count'] = count($bidderArray['price_items']);
            $result[] = $bidderArray;
        }

        return $result;
    }

    /**
     * Get bidder by ID with price items.
     */
    public static function getByIdWithPriceItems(string $id): ?array
    {
        $bidder = self::find($id);

        if (!$bidder) {
            return null;
        }

        $bidderArray = $bidder->toArray();
        $bidderArray['price_items'] = BidderPriceItem::where('bidder_id', $id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();

        return $bidderArray;
    }

    /**
     * Get price items for a bidder.
     */
    public static function getPriceItems(string $bidderId): array
    {
        return BidderPriceItem::where('bidder_id', $bidderId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Create bidder with price items.
     */
    public static function createWithPriceItems(array $data): array
    {
        DB::beginTransaction();

        try {
            $id = (string) Str::uuid();
            $priceItems = $data['price_items'] ?? [];
            unset($data['price_items']);

            $bidder = self::create(array_merge($data, ['id' => $id]));

            // Insert price items
            foreach ($priceItems as $item) {
                $currencyToSave = $item['currency_name'] ?? $item['currency'] ?? 'IRR';
                
                BidderPriceItem::create([
                    'bidder_id' => $id,
                    'amount' => $item['amount'],
                    'currency' => $currencyToSave,
                    'currency_name' => $item['currency_name'] ?? null,
                    'exchange_rate' => $item['exchange_rate'] ?? null,
                    'amount_in_rials' => $item['amount_in_rials'] ?? $item['amount'],
                    'is_adjustable' => $item['is_adjustable'] ?? false,
                    'description' => $item['description'] ?? null,
                ]);
            }

            DB::commit();
            return self::getByIdWithPriceItems($id);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("Error creating bidder: " . $e->getMessage());
        }
    }

    /**
     * Update bidder with price items.
     */
    public static function updateWithPriceItems(string $id, array $data): array
    {
        DB::beginTransaction();

        try {
            $bidder = self::find($id);
            
            if (!$bidder) {
                throw new \Exception("Bidder not found");
            }

            $priceItems = $data['price_items'] ?? null;
            unset($data['price_items']);

            // Update bidder fields
            if (!empty($data)) {
                $bidder->update($data);
            }

            // Update price items if provided
            if ($priceItems !== null) {
                // Delete existing items
                BidderPriceItem::where('bidder_id', $id)->delete();

                // Insert new items
                foreach ($priceItems as $item) {
                    $currencyToSave = $item['currency_name'] ?? $item['currency'] ?? 'IRR';
                    
                    BidderPriceItem::create([
                        'bidder_id' => $id,
                        'amount' => $item['amount'],
                        'currency' => $currencyToSave,
                        'currency_name' => $item['currency_name'] ?? null,
                        'exchange_rate' => $item['exchange_rate'] ?? null,
                        'amount_in_rials' => $item['amount_in_rials'] ?? $item['amount'],
                        'is_adjustable' => $item['is_adjustable'] ?? false,
                        'description' => $item['description'] ?? null,
                    ]);
                }
            }

            DB::commit();
            return self::getByIdWithPriceItems($id);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("Error updating bidder: " . $e->getMessage());
        }
    }
}


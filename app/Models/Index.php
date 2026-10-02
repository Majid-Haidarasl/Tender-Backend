<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Index extends Model
{
    use HasUuids;

    protected $table = 'indices';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'tender_id',
        'type',
        'value',
        'date',
        'description',
    ];

    protected $casts = [
        'value' => 'decimal:8',
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
     * Get the tender that owns the index.
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }

    /**
     * Get all indices for a tender.
     */
    public static function getByTenderId(string $tenderId): array
    {
        return self::where('tender_id', $tenderId)
            ->orderBy('type', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Get index by tender ID and type.
     */
    public static function getByTenderIdAndType(string $tenderId, string $type): ?self
    {
        return self::where('tender_id', $tenderId)
            ->where('type', $type)
            ->first();
    }

    /**
     * Create or update index.
     * If an index with the same tender_id and type exists, update it.
     */
    public static function createOrUpdate(array $data): self
    {
        $existing = self::getByTenderIdAndType($data['tender_id'], $data['type']);

        if ($existing) {
            $existing->update([
                'value' => $data['value'],
                'date' => $data['date'] ?? null,
                'description' => $data['description'] ?? '',
            ]);
            return $existing->fresh();
        }

        return self::create($data);
    }

    /**
     * Bulk create/update indices.
     */
    public static function bulkUpsert(array $indicesData): bool
    {
        DB::beginTransaction();

        try {
            foreach ($indicesData as $indexData) {
                $tenderId = $indexData['tender_id'];
                $type = $indexData['type'];
                $value = $indexData['value'];
                $date = $indexData['date'] ?? null;
                $description = $indexData['description'] ?? '';

                // Validate required fields
                if (empty($tenderId) || empty($type) || $value === null) {
                    throw new \Exception("Missing required fields: tender_id, type, or value for index {$type}");
                }

                // Validate value is a number (accept string numbers too)
                if ($value === '' || $value === null) {
                    throw new \Exception("Invalid value for index {$type}: value cannot be empty");
                }
                
                // Convert to number and validate
                if (!is_numeric($value)) {
                    throw new \Exception("Invalid value for index {$type}: must be a valid number, got: " . gettype($value) . " (" . var_export($value, true) . ")");
                }
                
                $numValue = floatval($value);
                if (!is_finite($numValue)) {
                    throw new \Exception("Invalid value for index {$type}: must be a finite number, got: " . var_export($value, true));
                }

                // Check if index already exists
                $existing = self::where('tender_id', $tenderId)
                    ->where('type', $type)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'value' => $numValue,
                        'date' => $date,
                        'description' => (string) $description,
                    ]);
                } else {
                    self::create([
                        'tender_id' => $tenderId,
                        'type' => $type,
                        'value' => $numValue,
                        'date' => $date,
                        'description' => (string) $description,
                    ]);
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("Error bulk upserting indices: " . $e->getMessage());
        }
    }
}


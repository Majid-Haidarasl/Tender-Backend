<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasUuids;

    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_SYSTEM = 'system';

    protected $table = 'notifications';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'message_id',
        'title',
        'message',
        'type',
        'source',
        'stage',
        'details',
        'context',
        'read',
        'read_at',
        'user_id',
        'tender_id',
    ];

    protected $casts = [
        'read' => 'boolean',
        'details' => 'array',
        'context' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'read' => false,
        'type' => 'info',
        'source' => self::SOURCE_SYSTEM,
    ];

    /**
     * User inbox: admin messages only (not system logs / toasts / workflow alerts).
     */
    public function scopeInboxFor($query, string $userId)
    {
        return $query
            ->where('source', self::SOURCE_ADMIN)
            ->where('user_id', $userId);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(): void
    {
        $this->update([
            'read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * Mark notification as unread
     */
    public function markAsUnread(): void
    {
        $this->update([
            'read' => false,
            'read_at' => null,
        ]);
    }

    /**
     * Get unread notifications count
     */
    public static function getUnreadCount(?string $userId = null): int
    {
        if (!$userId) {
            return 0;
        }

        return static::inboxFor($userId)->where('read', false)->count();
    }

    /**
     * Get inbox notifications with optional filters
     */
    public static function getAll(?string $userId = null, ?string $type = null, ?bool $read = null, ?int $limit = null)
    {
        if (!$userId) {
            return collect();
        }

        $query = static::inboxFor($userId);
        if ($type) {
            $query->where('type', $type);
        }
        
        if ($read !== null) {
            $query->where('read', $read);
        }
        
        $query->orderBy('created_at', 'desc');
        
        if ($limit) {
            $query->limit($limit);
        }
        
        return $query->get();
    }

    /**
     * Relationship with User (if needed)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relationship with Tender (if needed)
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }
}


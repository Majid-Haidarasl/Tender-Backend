<?php

namespace App\Models;

use App\Services\SupportContactService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasUuids;

    protected $table = 'messages';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'recipient_id',
        'conversation_id',
        'subject',
        'message',
        'reply',
        'replied_at',
        'replied_by',
        'status',
        'is_read',
        'read_at',
        'is_archived',
        'archived_at',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_read' => 'boolean',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Relationship with User (sender)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relationship with User (replier)
     */
    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    /**
     * Relationship with User (recipient)
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * Get messages for a user
     */
    public static function getUserMessages(string $userId, ?string $status = null, ?int $limit = null)
    {
        $query = static::where('user_id', $userId);
        
        if ($status) {
            $query->where('status', $status);
        }
        
        $query->orderBy('created_at', 'desc');
        
        if ($limit) {
            $query->limit($limit);
        }
        
        return $query->get();
    }

    /**
     * Get unread messages count for a user (messages with replies that user hasn't seen)
     * Note: This counts replied messages as "unread" since user hasn't marked them as read yet
     */
    public static function getUnreadCount(string $userId): int
    {
        // Count messages that have been replied to (status = 'replied' and replied_at is not null)
        // These are considered "unread" because user hasn't seen the reply yet
        return static::where('user_id', $userId)
            ->where('status', 'replied')
            ->whereNotNull('replied_at')
            ->count();
    }

    /**
     * Mark message as replied
     */
    public function markAsReplied(string $replierId, string $reply): void
    {
        $this->update([
            'reply' => $reply,
            'replied_at' => now(),
            'replied_by' => $replierId,
            'status' => 'replied',
        ]);
    }

    /**
     * Close message
     */
    public function close(): void
    {
        $this->update([
            'status' => 'closed',
        ]);
    }

    public static function conversationIdFor(string $userId, string $recipientId): string
    {
        $ids = [$userId, $recipientId];
        sort($ids);

        return md5(implode('_', $ids));
    }

    public static function resolveConversationId(Message $message): string
    {
        if ($message->conversation_id) {
            return $message->conversation_id;
        }

        $userId = $message->user_id;
        $recipientId = $message->recipient_id;

        if ($userId && $recipientId) {
            return self::conversationIdFor($userId, $recipientId);
        }

        if ($userId && SupportContactService::isSupportMessage($message)) {
            $superAdminId = SupportContactService::superAdminId();
            if ($superAdminId) {
                return self::conversationIdFor($userId, $superAdminId);
            }
        }

        return 'single_' . $message->id;
    }
}


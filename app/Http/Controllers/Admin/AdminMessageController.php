<?php



namespace App\Http\Controllers\Admin;



use App\Http\Controllers\Controller;

use App\Models\Message;

use App\Models\Notification;

use App\Models\User;

use App\Services\AdminAuditService;

use App\Services\SupportContactService;

use Illuminate\Database\Eloquent\Builder;

use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Str;



class AdminMessageController extends Controller

{

    private function messageWithRelations(string $id): ?Message

    {

        return Message::with([

            'user:id,username,full_name,avatar',

            'recipient:id,username,full_name,avatar',

            'replier:id,username,full_name,avatar',

        ])->find($id);

    }



    private function conversationQuery(Message $message): Builder

    {

        if ($message->conversation_id) {

            return Message::where('conversation_id', $message->conversation_id);

        }



        return Message::where('id', $message->id);

    }



    private function conversationMessages(string $conversationId)

    {

        $relations = [

            'user:id,username,full_name,avatar',

            'recipient:id,username,full_name,avatar',

            'replier:id,username,full_name,avatar',

        ];



        if (str_starts_with($conversationId, 'single_')) {

            return Message::where('id', substr($conversationId, 7))

                ->with($relations)

                ->orderBy('created_at')

                ->get();

        }



        $thread = Message::where('conversation_id', $conversationId)

            ->with($relations)

            ->orderBy('created_at')

            ->get();



        $orphans = Message::with($relations)

            ->whereNull('conversation_id')

            ->orderBy('created_at')

            ->get()

            ->filter(fn (Message $m) => Message::resolveConversationId($m) === $conversationId);



        if ($orphans->isNotEmpty()) {

            $thread = $thread->merge($orphans)->sortBy('created_at')->values();

        }



        if ($thread->isEmpty()) {

            return Message::with($relations)

                ->orderBy('created_at')

                ->get()

                ->filter(fn (Message $m) => Message::resolveConversationId($m) === $conversationId)

                ->values();

        }



        return $thread;

    }



    private function conversationEndUser($messages): ?User

    {

        $superAdminId = SupportContactService::superAdminId();



        foreach ($messages as $message) {

            if ($superAdminId && $message->user_id !== $superAdminId && $message->user) {

                return $message->user;

            }

        }



        foreach ($messages as $message) {

            if ($superAdminId && $message->recipient_id && $message->recipient_id !== $superAdminId && $message->recipient) {

                return $message->recipient;

            }

        }



        return $messages->first()?->user;

    }



    private function ensureSupportMessage(Message $message): ?JsonResponse

    {

        if (!SupportContactService::isSupportMessage($message)) {

            return $this->errorResponse('فقط گفت‌وگوهای پشتیبانی سایت قابل مدیریت است', 403);

        }



        return null;

    }



    public function index(Request $request): JsonResponse

    {

        $relations = [

            'user:id,username,full_name,avatar',

            'recipient:id,username,full_name,avatar',

            'replier:id,username,full_name,avatar',

        ];



        $conversationId = $request->query('conversation_id');

        if ($conversationId) {

            return $this->successResponse($this->conversationMessages($conversationId));

        }



        $query = Message::with($relations);



        $archived = $request->query('archived');

        if ($archived === '1' || $archived === 1 || $archived === true) {

            $query->where('is_archived', true);

        } else {

            $query->where('is_archived', false);

        }



        if ($request->query('support_only')) {

            SupportContactService::applySupportInboxFilter($query);

        }

        if ($search = $request->query('search')) {

            $query->where(function ($q) use ($search) {

                $q->where('subject', 'like', "%{$search}%")

                  ->orWhere('message', 'like', "%{$search}%")

                  ->orWhere('reply', 'like', "%{$search}%")

                  ->orWhereHas('user', function ($uq) use ($search) {

                      $uq->where('username', 'like', "%{$search}%")

                        ->orWhere('full_name', 'like', "%{$search}%");

                  });

            });

        }



        $status = $request->query('status');

        $limit = (int) $request->query('limit', 100);

        $type = $request->query('type', 'conversations');



        $messages = $query->orderByDesc('created_at')->get();



        if ($type === 'messages') {

            if ($status) {

                $messages = $messages->where('status', $status);

            }



            return $this->successResponse($messages->take($limit)->values());

        }



        $conversations = $messages

            ->groupBy(fn (Message $m) => Message::resolveConversationId($m))

            ->map(function ($group) {

                $sorted = $group->sortByDesc('created_at');

                $lastMessage = $sorted->first();

                $superAdminId = SupportContactService::superAdminId();

                $isUserPending = fn (Message $m) => $m->status === 'pending'

                    && (!$superAdminId || $m->user_id !== $superAdminId);

                $hasPending = $group->contains($isUserPending);

                $allClosed = $group->every(fn (Message $m) => $m->status === 'closed');

                $aggregateStatus = $hasPending

                    ? 'pending'

                    : ($allClosed ? 'closed' : 'replied');



                $subjectMessage = $group->first(fn (Message $m) => filled($m->subject));

                $endUser = $this->conversationEndUser($group);



                return [

                    'conversation_id' => Message::resolveConversationId($lastMessage),

                    'user' => $endUser,

                    'subject' => $subjectMessage?->subject ?? $lastMessage->subject ?? 'بدون موضوع',

                    'status' => $aggregateStatus,

                    'last_message' => $lastMessage,

                    'message_count' => $group->count(),

                    'pending_count' => $group->filter($isUserPending)->count(),

                ];

            })

            ->sortByDesc(fn ($conv) => $conv['last_message']->created_at)

            ->values();



        if ($status && $status !== 'all') {

            $conversations = $conversations->filter(fn ($conv) => $conv['status'] === $status)->values();

        }



        return $this->successResponse($conversations->take($limit)->values());

    }



    public function show(string $id): JsonResponse

    {

        $message = $this->messageWithRelations($id);

        if (!$message) {

            return $this->notFoundResponse('پیام یافت نشد');

        }

        return $this->successResponse($message);

    }



    public function reply(Request $request, string $id): JsonResponse

    {

        $message = Message::find($id);

        if (!$message) {

            return $this->notFoundResponse('پیام یافت نشد');

        }



        $validator = Validator::make($request->all(), [

            'reply' => 'required|string|min:3',

        ], [

            'reply.required' => 'متن پاسخ الزامی است',

            'reply.min' => 'متن پاسخ باید حداقل ۳ کاراکتر باشد',

        ]);



        if ($validator->fails()) {

            return $this->validationErrorResponse($validator->errors());

        }



        $admin = $request->user();

        $message->markAsReplied($admin->id, $request->input('reply'));



        if ($message->user_id && $message->user_id !== $admin->id) {

            Notification::create([

                'id' => (string) Str::uuid(),

                'user_id' => $message->user_id,

                'type' => 'info',

                'source' => Notification::SOURCE_ADMIN,

                'title' => 'پاسخ پشتیبانی: ' . ($message->subject ?: 'پیام شما'),

                'message' => mb_substr($request->input('reply'), 0, 200),

                'read' => false,

            ]);

        }



        AdminAuditService::log(

            $admin->id, 'message.reply', 'message', $message->id,

            null, ['subject' => $message->subject], 'پاسخ به پیام پشتیبانی', $request

        );



        return $this->successResponse($this->messageWithRelations($message->id), 'پاسخ با موفقیت ارسال شد');

    }



    public function sendConversationMessage(Request $request): JsonResponse

    {

        $validator = Validator::make($request->all(), [

            'conversation_id' => 'required|string',

            'message' => 'required|string|min:3',

        ], [

            'conversation_id.required' => 'شناسه گفت‌وگو الزامی است',

            'message.required' => 'متن پیام الزامی است',

            'message.min' => 'متن پیام باید حداقل ۳ کاراکتر باشد',

        ]);



        if ($validator->fails()) {

            return $this->validationErrorResponse($validator->errors());

        }



        $conversationId = $request->input('conversation_id');

        $thread = $this->conversationMessages($conversationId);



        if ($thread->isEmpty()) {

            return $this->notFoundResponse('گفت‌وگو یافت نشد');

        }



        if ($thread->every(fn (Message $m) => $m->status === 'closed')) {

            return $this->errorResponse('این گفت‌وگو پایان یافته است', 422);

        }



        $admin = $request->user();

        $endUser = $this->conversationEndUser($thread);



        if (!$endUser || $endUser->id === $admin->id) {

            return $this->errorResponse('کاربر گفت‌وگو یافت نشد', 422);

        }



        $resolvedConversationId = Message::resolveConversationId($thread->first());

        $subjectMessage = $thread->first(fn (Message $m) => filled($m->subject));

        $subject = $subjectMessage?->subject ?? 'ادامه گفت‌وگو';



        $message = Message::create([

            'id' => (string) Str::uuid(),

            'user_id' => $admin->id,

            'recipient_id' => $endUser->id,

            'conversation_id' => $resolvedConversationId,

            'subject' => $subject,

            'message' => $request->input('message'),

            'status' => 'replied',

            'is_read' => false,

        ]);



        Notification::create([

            'id' => (string) Str::uuid(),

            'user_id' => $endUser->id,

            'type' => 'info',

            'source' => Notification::SOURCE_ADMIN,

            'title' => 'پیام جدید از پشتیبانی',

            'message' => mb_substr($request->input('message'), 0, 200),

            'read' => false,

        ]);



        AdminAuditService::log(

            $admin->id, 'message.send', 'message', $message->id,

            null, ['conversation_id' => $resolvedConversationId], 'ارسال پیام در گفت‌وگو', $request

        );



        return $this->successResponse($this->messageWithRelations($message->id), 'پیام با موفقیت ارسال شد', 201);

    }



    public function broadcast(Request $request): JsonResponse

    {

        $validator = Validator::make($request->all(), [

            'subject' => 'required|string|max:500',

            'message' => 'required|string|min:3',

            'target' => 'nullable|in:all,active,role',

            'role' => 'nullable|in:user,admin',

            'user_ids' => 'nullable|array',

            'user_ids.*' => 'string|exists:users,id',

            'send_notification' => 'nullable|boolean',

        ], [

            'subject.required' => 'موضوع پیام الزامی است',

            'message.required' => 'متن پیام الزامی است',

            'message.min' => 'متن پیام باید حداقل ۳ کاراکتر باشد',

        ]);



        if ($validator->fails()) {

            return $this->validationErrorResponse($validator->errors());

        }



        $admin = $request->user();

        $target = $request->input('target', 'all');



        $usersQuery = User::where('is_active', true)->where('id', '!=', $admin->id);



        if ($target === 'active') {

            // already filtered

        } elseif ($target === 'role') {

            $usersQuery->where('role', $request->input('role', 'user'));

        } elseif ($request->input('user_ids')) {

            $usersQuery->whereIn('id', $request->input('user_ids'));

        }



        $recipients = $usersQuery->get();

        $sent = 0;

        $sendNotification = $request->boolean('send_notification', true);



        foreach ($recipients as $recipient) {

            Message::create([

                'id' => (string) Str::uuid(),

                'user_id' => $admin->id,

                'recipient_id' => $recipient->id,

                'conversation_id' => self::makeConversationId($admin->id, $recipient->id),

                'subject' => $request->input('subject'),

                'message' => $request->input('message'),

                'status' => 'pending',

                'is_read' => false,

            ]);

            $sent++;



            if ($sendNotification) {

                Notification::create([

                    'id' => (string) Str::uuid(),

                    'user_id' => $recipient->id,

                    'type' => 'info',

                    'source' => Notification::SOURCE_ADMIN,

                    'title' => $request->input('subject'),

                    'message' => mb_substr($request->input('message'), 0, 200),

                    'read' => false,

                ]);

            }

        }



        AdminAuditService::log(

            $admin->id, 'message.broadcast', null, null,

            null, ['subject' => $request->input('subject'), 'sent_count' => $sent],

            "ارسال پیام گروهی به {$sent} کاربر", $request

        );



        return $this->successResponse(['sent_count' => $sent], "پیام به {$sent} کاربر ارسال شد");

    }



    public function close(string $id): JsonResponse

    {

        $message = Message::find($id);

        if (!$message) {

            return $this->notFoundResponse('پیام یافت نشد');

        }

        $this->conversationQuery($message)
            ->where('status', '!=', 'closed')
            ->each(fn (Message $m) => $m->close());

        return $this->successResponse($this->messageWithRelations($message->id), 'گفت‌وگو پایان یافت');

    }



    public function destroyConversation(string $id, Request $request): JsonResponse

    {

        $message = Message::find($id);

        if (!$message) {

            return $this->notFoundResponse('پیام یافت نشد');

        }



        if ($error = $this->ensureSupportMessage($message)) {

            return $error;

        }



        $query = $this->conversationQuery($message);

        $deletedCount = $query->count();

        $query->delete();



        AdminAuditService::log(

            $request->user()->id,

            'message.delete_conversation',

            'message',

            $message->id,

            null,

            ['conversation_id' => $message->conversation_id, 'deleted_count' => $deletedCount],

            'حذف گفت‌وگوی پشتیبانی',

            $request

        );



        return $this->successResponse(

            ['deleted_count' => $deletedCount],

            'گفت‌وگو با موفقیت از پایگاه داده حذف شد'

        );

    }



    public function archiveConversation(string $id, Request $request): JsonResponse

    {

        $message = Message::find($id);

        if (!$message) {

            return $this->notFoundResponse('پیام یافت نشد');

        }



        if ($error = $this->ensureSupportMessage($message)) {

            return $error;

        }



        $archivedCount = $this->conversationQuery($message)->update([

            'is_archived' => true,

            'archived_at' => now(),

        ]);



        AdminAuditService::log(

            $request->user()->id,

            'message.archive_conversation',

            'message',

            $message->id,

            null,

            ['conversation_id' => $message->conversation_id, 'archived_count' => $archivedCount],

            'آرشیو گفت‌وگوی پشتیبانی',

            $request

        );



        return $this->successResponse(

            ['archived_count' => $archivedCount],

            'گفت‌وگو به آرشیو منتقل شد'

        );

    }



    public function unarchiveConversation(string $id, Request $request): JsonResponse

    {

        $message = Message::find($id);

        if (!$message) {

            return $this->notFoundResponse('پیام یافت نشد');

        }



        if ($error = $this->ensureSupportMessage($message)) {

            return $error;

        }



        $restoredCount = $this->conversationQuery($message)->update([

            'is_archived' => false,

            'archived_at' => null,

        ]);



        AdminAuditService::log(

            $request->user()->id,

            'message.unarchive_conversation',

            'message',

            $message->id,

            null,

            ['conversation_id' => $message->conversation_id, 'restored_count' => $restoredCount],

            'بازگردانی گفت‌وگو از آرشیو',

            $request

        );



        return $this->successResponse(

            ['restored_count' => $restoredCount],

            'گفت‌وگو از آرشیو بازگردانده شد'

        );

    }



    public function stats(): JsonResponse

    {

        $active = Message::where('is_archived', false);

        $archived = Message::where('is_archived', true);



        return $this->successResponse([

            'total' => (clone $active)->count(),

            'pending' => (clone $active)->where('status', 'pending')->count(),

            'replied' => (clone $active)->where('status', 'replied')->count(),

            'closed' => (clone $active)->where('status', 'closed')->count(),

            'support_pending' => SupportContactService::applySupportInboxFilter(

                (clone $active)->where('status', 'pending')

            )->count(),

            'archived' => SupportContactService::applySupportInboxFilter(clone $archived)->count(),

            'unread' => (clone $active)->where('is_read', false)->count(),

        ]);

    }



    private static function makeConversationId(string $userId, string $recipientId): string

    {

        $ids = [$userId, $recipientId];

        sort($ids);

        return md5(implode('_', $ids));

    }

}



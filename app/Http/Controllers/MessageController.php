<?php



namespace App\Http\Controllers;



use App\Models\Message;

use App\Models\MessageConversationHide;

use App\Services\SupportContactService;

use Illuminate\Database\Eloquent\Builder;

use Illuminate\Http\Request;

use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Str;



class MessageController extends Controller

{

    private function getUserId(Request $request): ?string

    {

        try {

            $authHeader = $request->header('Authorization');

            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {

                return null;

            }



            return $request->user()?->id;

        } catch (\Exception $e) {

            return null;

        }

    }



    private function messageRelations(): array

    {

        return [

            'user:id,username,full_name,avatar',

            'recipient:id,username,full_name,avatar',

            'replier:id,username,full_name,avatar',

        ];

    }



    private function conversationQuery(Message $message): Builder

    {

        if ($message->conversation_id) {

            return Message::where('conversation_id', $message->conversation_id);

        }



        return Message::where('id', $message->id);

    }



    private function userCanAccessConversation(string $userId, string $conversationId): bool

    {

        return Message::where('conversation_id', $conversationId)

            ->where(function (Builder $q) use ($userId) {

                $q->where('user_id', $userId)->orWhere('recipient_id', $userId);

            })

            ->exists();

    }



    private function hiddenConversationIds(string $userId): array

    {

        return MessageConversationHide::where('user_id', $userId)

            ->pluck('conversation_id')

            ->all();

    }



    public function supportContact(): JsonResponse

    {

        $superAdmin = SupportContactService::superAdmin();



        if (!$superAdmin) {

            return $this->errorResponse('پشتیبان سایت یافت نشد', 404);

        }



        return $this->successResponse([

            'id' => $superAdmin->id,

            'username' => $superAdmin->username,

            'full_name' => $superAdmin->full_name,

            'label' => 'پشتیبان سایت',

            'avatar' => $superAdmin->getAvatarPublicUrl(),

        ]);

    }



    public function index(Request $request): JsonResponse

    {

        try {

            $userId = $this->getUserId($request);

            if (!$userId) {

                return $this->errorResponse('توکن احراز هویت یافت نشد', 401);

            }



            $status = $request->query('status');

            $limit = (int) $request->query('limit', 100);

            $type = $request->query('type', 'conversations');

            $conversationId = $request->query('conversation_id');

            $hiddenIds = $this->hiddenConversationIds($userId);



            if ($conversationId) {

                if (!$this->userCanAccessConversation($userId, $conversationId)) {

                    return $this->errorResponse('دسترسی به این گفت‌وگو مجاز نیست', 403);

                }



                $messages = Message::where('conversation_id', $conversationId)

                    ->where(function ($query) use ($userId) {

                        $query->where('user_id', $userId)

                              ->orWhere('recipient_id', $userId);

                    })

                    ->with($this->messageRelations())

                    ->orderBy('created_at', 'asc')

                    ->get();



                return $this->successResponse($messages);

            }



            if ($type === 'conversations') {

                $messages = Message::where(function ($q) use ($userId) {

                    $q->where('user_id', $userId)->orWhere('recipient_id', $userId);

                })

                    ->where(function (Builder $q) {

                        SupportContactService::applySupportInboxFilter($q);

                    })

                    ->with($this->messageRelations())

                    ->orderByDesc('created_at')

                    ->get();



                $conversations = $messages

                    ->groupBy(fn (Message $m) => Message::resolveConversationId($m))

                    ->map(function ($group) use ($userId) {

                        $sorted = $group->sortByDesc('created_at');

                        $lastMessage = $sorted->first();

                        $hasPending = $group->contains(fn (Message $m) => $m->status === 'pending');

                        $allClosed = $group->every(fn (Message $m) => $m->status === 'closed');

                        $aggregateStatus = $hasPending

                            ? 'pending'

                            : ($allClosed ? 'closed' : 'replied');



                        return [

                            'conversation_id' => Message::resolveConversationId($lastMessage),

                            'other_user' => $lastMessage->recipient_id === $userId

                                ? $lastMessage->user

                                : ($lastMessage->recipient ?? SupportContactService::superAdmin()),

                            'last_message' => $lastMessage,

                            'subject' => $sorted->first(fn (Message $m) => filled($m->subject))?->subject

                                ?? $lastMessage->subject

                                ?? 'گفت‌وگو با پشتیبان',

                            'status' => $aggregateStatus,

                            'unread_count' => $group->where('user_id', $userId)

                                ->where('status', 'replied')

                                ->whereNotNull('replied_at')

                                ->count(),

                            'message_count' => $group->count(),

                        ];

                    })

                    ->filter(fn ($conv) => !in_array($conv['conversation_id'], $hiddenIds, true))

                    ->sortByDesc(fn ($conv) => $conv['last_message']->created_at)

                    ->values();



                if ($status && $status !== 'all') {

                    $conversations = $conversations->filter(fn ($conv) => $conv['status'] === $status)->values();

                }



                return $this->successResponse($conversations->take($limit)->values());

            }



            $query = Message::where(function ($q) use ($userId) {

                $q->where('user_id', $userId)->orWhere('recipient_id', $userId);

            });



            if ($status) {

                $query->where('status', $status);

            }



            $messages = $query->with($this->messageRelations())

                ->orderByDesc('created_at')

                ->limit($limit)

                ->get();



            return $this->successResponse($messages);

        } catch (\Exception $e) {

            return $this->errorResponse('خطا در دریافت پیام‌ها: ' . $e->getMessage(), 500);

        }

    }



    public function show(Request $request, string $id): JsonResponse

    {

        try {

            $userId = $this->getUserId($request);

            if (!$userId) {

                return $this->errorResponse('توکن احراز هویت یافت نشد', 401);

            }



            $message = Message::with($this->messageRelations())->find($id);



            if (!$message) {

                return $this->notFoundResponse('پیام یافت نشد');

            }



            if ($message->user_id !== $userId && $message->recipient_id !== $userId) {

                return $this->errorResponse('دسترسی غیرمجاز', 403);

            }



            if ($message->recipient_id === $userId && !$message->is_read) {

                $message->update([

                    'is_read' => true,

                    'read_at' => now(),

                ]);

            }



            return $this->successResponse($message);

        } catch (\Exception $e) {

            return $this->errorResponse('خطا در دریافت پیام: ' . $e->getMessage(), 500);

        }

    }



    public function store(Request $request): JsonResponse

    {

        try {

            $userId = $this->getUserId($request);

            if (!$userId) {

                return $this->errorResponse('توکن احراز هویت یافت نشد', 401);

            }



            $conversationId = $request->input('conversation_id');

            $rules = [

                'recipient_id' => 'nullable|string|exists:users,id',

                'subject' => ($conversationId ? 'nullable' : 'required') . '|string|max:500',

                'message' => 'required|string|min:3',

                'conversation_id' => 'nullable|string',

            ];



            $validator = Validator::make($request->all(), $rules, [

                'recipient_id.exists' => 'کاربر گیرنده یافت نشد',

                'subject.required' => 'موضوع پیام الزامی است',

                'subject.max' => 'موضوع پیام نباید بیشتر از ۵۰۰ کاراکتر باشد',

                'message.required' => 'متن پیام الزامی است',

                'message.min' => 'متن پیام باید حداقل ۳ کاراکتر باشد',

            ]);



            if ($validator->fails()) {

                return $this->validationErrorResponse($validator->errors());

            }



            $recipientId = null;

            $subject = trim((string) $request->input('subject', ''));



            if ($conversationId) {

                if (!$this->userCanAccessConversation($userId, $conversationId)) {

                    return $this->errorResponse('گفت‌وگوی انتخاب‌شده یافت نشد', 404);

                }



                $existing = Message::where('conversation_id', $conversationId)

                    ->orderBy('created_at')

                    ->first();



                if (!$existing) {

                    return $this->notFoundResponse('گفت‌وگو یافت نشد');

                }



                $conversationMessages = Message::where('conversation_id', $conversationId)->get();

                if ($conversationMessages->isNotEmpty() && $conversationMessages->every(fn (Message $m) => $m->status === 'closed')) {

                    return $this->errorResponse('این گفت‌وگو پایان یافته است', 422);

                }



                $recipientId = $existing->user_id === $userId

                    ? $existing->recipient_id

                    : $existing->user_id;



                if (!$subject) {

                    $subject = $existing->subject ?: 'ادامه گفت‌وگو';

                }



                MessageConversationHide::where('user_id', $userId)

                    ->where('conversation_id', $conversationId)

                    ->delete();

            } else {

                $recipientId = SupportContactService::resolveRecipientId(

                    $request->input('recipient_id'),

                    $userId

                );



                if ($recipientId) {

                    $conversationId = Message::conversationIdFor($userId, $recipientId);

                }

            }



            $message = Message::create([

                'id' => (string) Str::uuid(),

                'user_id' => $userId,

                'recipient_id' => $recipientId,

                'conversation_id' => $conversationId,

                'subject' => $subject,

                'message' => $request->input('message'),

                'status' => 'pending',

                'is_read' => false,

            ]);



            $message->load($this->messageRelations());



            return $this->successResponse($message, 'پیام با موفقیت ارسال شد', 201);

        } catch (\Exception $e) {

            return $this->errorResponse('خطا در ارسال پیام: ' . $e->getMessage(), 500);

        }

    }



    public function update(Request $request, string $id): JsonResponse

    {

        try {

            $userId = $this->getUserId($request);

            if (!$userId) {

                return $this->errorResponse('توکن احراز هویت یافت نشد', 401);

            }



            $message = Message::find($id);



            if (!$message) {

                return $this->notFoundResponse('پیام یافت نشد');

            }



            if ($message->user_id !== $userId && $message->recipient_id !== $userId) {

                return $this->errorResponse('دسترسی غیرمجاز', 403);

            }



            $action = $request->input('action');



            if ($action === 'close') {

                $this->conversationQuery($message)

                    ->where(function (Builder $q) use ($userId) {

                        $q->where('user_id', $userId)->orWhere('recipient_id', $userId);

                    })

                    ->where('status', '!=', 'closed')

                    ->each(fn (Message $m) => $m->close());



                return $this->successResponse(null, 'گفت‌وگو پایان یافت');

            }



            if ($action === 'mark_read') {

                if ($message->recipient_id === $userId) {

                    $message->update([

                        'is_read' => true,

                        'read_at' => now(),

                    ]);



                    return $this->successResponse($message, 'پیام به عنوان خوانده شده علامت زده شد');

                }



                return $this->errorResponse('فقط گیرنده می‌تواند پیام را به عنوان خوانده شده علامت بزند', 403);

            }



            return $this->errorResponse('عملیات نامعتبر است', 400);

        } catch (\Exception $e) {

            return $this->errorResponse('خطا در به‌روزرسانی پیام: ' . $e->getMessage(), 500);

        }

    }



    public function hideConversation(Request $request, string $id): JsonResponse

    {

        $userId = $this->getUserId($request);

        if (!$userId) {

            return $this->errorResponse('توکن احراز هویت یافت نشد', 401);

        }



        $message = Message::find($id);

        if (!$message) {

            return $this->notFoundResponse('پیام یافت نشد');

        }



        if ($message->user_id !== $userId && $message->recipient_id !== $userId) {

            return $this->errorResponse('دسترسی غیرمجاز', 403);

        }



        $conversationId = Message::resolveConversationId($message);



        MessageConversationHide::updateOrCreate(

            ['user_id' => $userId, 'conversation_id' => $conversationId],

            ['hidden_at' => now()]

        );



        return $this->successResponse(null, 'گفت‌وگو از لیست شما حذف شد');

    }



    public function unreadCount(Request $request): JsonResponse

    {

        try {

            $userId = $this->getUserId($request);

            if (!$userId) {

                return $this->errorResponse('توکن احراز هویت یافت نشد', 401);

            }



            $hiddenIds = $this->hiddenConversationIds($userId);



            $unreadReceived = Message::where('recipient_id', $userId)

                ->where('is_read', false)

                ->when($hiddenIds, fn (Builder $q) => $q->whereNotIn('conversation_id', $hiddenIds))

                ->count();



            $unreadReplied = Message::where('user_id', $userId)

                ->where('status', 'replied')

                ->whereNotNull('replied_at')

                ->where(function ($q) {

                    SupportContactService::applySupportInboxFilter($q);

                })

                ->when($hiddenIds, fn (Builder $q) => $q->whereNotIn('conversation_id', $hiddenIds))

                ->count();



            return response()->json([
                'success' => true,
                'count' => $unreadReceived + $unreadReplied,
                'received' => $unreadReceived,
                'replied' => $unreadReplied,
            ]);

        } catch (\Exception $e) {

            return $this->errorResponse('خطا در دریافت تعداد پیام‌ها: ' . $e->getMessage(), 500);

        }

    }

}



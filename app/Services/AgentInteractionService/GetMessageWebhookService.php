<?php

namespace App\Services\AgentInteractionService;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\Jobs\ProcessConversation;
use App\Jobs\ProcessAdminConversation;
use Illuminate\Support\Facades\Log;
class GetMessageWebhookService
{
    public function receive(Request $request): array
        {
            try {
                $payload = $this->normalizeHeywave($request->all());
                if (($payload['event']['type'] ?? null) === 'statuses') {
                    return [
                        'status' => true,
                        'messages_stored' => 0,
                    ];
                }

                if (!isset($payload['messages']) || !is_array($payload['messages'])) {
                    throw new InvalidArgumentException('Invalid webhook payload.');
                }
                $channelId = $payload['channel_id'] ?? null;
                if (!$channelId) {
                    throw new InvalidArgumentException('channel_id is required.');
                }
                $storedMessages = 0;
                $conversationIds = [];
                DB::transaction(function () use (
                    $payload,
                    $channelId,
                    &$storedMessages,
                    &$conversationIds
                ) {
                    foreach ($payload['messages'] as $messageData) {
                        if (!empty($messageData['from_me'])) {
                            continue;
                        }
                        if (($messageData['source'] ?? null) === 'system'
                            || ($messageData['type'] ?? null) === 'unknown'
                        ) {
                            continue;
                        }
                        $sender = $messageData['from'] ?? null;
                        if (!$sender) {
                            continue;
                        }
                        $messageId = $messageData['id'] ?? null;
                        if (!$messageId) {
                            continue;
                        }
                        $conversation = Conversation::firstOrCreate(
                            [
                                'sender' => $messageData['from'],
                                'channel_id' => $channelId,
                            ],
                            [
                                'chat_id' => $messageData['chat_id'] ?? null,
                                'last_message_at' => now(),
                                'status' => 'active',
                                'processing' => false,
                            ]
                        );
                        Message::updateOrCreate(
                            [
                                'message_id' => $messageId,
                            ],
                            [
                                'conversation_id' => $conversation->id,
                                'sender' => $sender,
                                'from_me' => $messageData['from_me'] ?? false,
                                'type' => $messageData['type'] ?? null,
                                'message_timestamp' => $messageData['timestamp'] ?? null,
                                'source' => $messageData['source'] ?? null,
                                'chat_id' => $messageData['chat_id'] ?? null,
                                'text' => $messageData['text']['body'] ?? null,
                                'from_name' => $messageData['from_name'] ?? null,
                                'payload' => $messageData,
                            ]
                        );
                        $conversation->update([
                            'chat_id' => $messageData['chat_id'] ?? $conversation->chat_id,
                            'last_message_at' => now(),
                        ]);
                        $conversationIds[$conversation->id] = true;
                        $storedMessages++;
                    }
                });
                foreach (array_keys($conversationIds) as $conversationId) {
                    ProcessConversation::dispatch(
                        $conversationId,
                        now()->toDateTimeString(),
                        1
                    )->delay(now()->addSeconds(3));
                }
                return [
                    'status' => true,
                    'messages_stored' => $storedMessages,
                ];
            } catch (InvalidArgumentException $e) {
                Log::warning('Invalid webhook payload.', [
                    'message' => $e->getMessage(),
                    'payload' => $request->all(),
                ]);
                throw $e;
            } catch (\Throwable $e) {
                Log::error('Webhook processing failed.', [
                    'message' => $e->getMessage(),
                    'payload' => $request->all(),
                ]);
                throw $e;
            }
        }
    public function receiveadmin(Request $request): array
        {
            try {
                $payload = $this->normalizeHeywave($request->all());
                if (($payload['event']['type'] ?? null) === 'statuses') {
                    return [
                        'status' => true,
                        'messages_stored' => 0,
                    ];
                }
                if (!isset($payload['messages']) || !is_array($payload['messages'])) {
                    throw new InvalidArgumentException('Invalid webhook payload.');
                }
                $channelId = $payload['channel_id'] ?? null;
                if (!$channelId) {
                    throw new InvalidArgumentException('channel_id is required.');
                }
                $storedMessages = 0;
                $conversationIds = [];
                DB::transaction(function () use (
                    $payload,
                    $channelId,
                    &$storedMessages,
                    &$conversationIds
                ) {
                    foreach ($payload['messages'] as $messageData) {
                        if (!empty($messageData['from_me'])) {
                            continue;
                        }
                        if (($messageData['source'] ?? null) === 'system'
                            || ($messageData['type'] ?? null) === 'unknown'
                        ) {
                            continue;
                        }
                        $sender = $messageData['from'] ?? null;
                        if (!$sender) {
                            continue;
                        }
                        $messageId = $messageData['id'] ?? null;
                        if (!$messageId) {
                            continue;
                        }
                        $conversation = Conversation::firstOrCreate(
                            [
                                'sender' => $messageData['from'],
                                'channel_id' => $channelId,
                            ],
                            [
                                'chat_id' => $messageData['chat_id'] ?? null,
                                'last_message_at' => now(),
                                'status' => 'active',
                                'processing' => false,
                            ]
                        );
                        Message::updateOrCreate(
                            [
                                'message_id' => $messageId,
                            ],
                            [
                                'conversation_id' => $conversation->id,
                                'sender' => $sender,
                                'from_me' => $messageData['from_me'] ?? false,
                                'type' => $messageData['type'] ?? null,
                                'message_timestamp' => $messageData['timestamp'] ?? null,
                                'source' => $messageData['source'] ?? null,
                                'chat_id' => $messageData['chat_id'] ?? null,
                                'text' => $messageData['text']['body'] ?? null,
                                'from_name' => $messageData['from_name'] ?? null,
                                'payload' => $messageData,
                            ]
                        );
                        $conversation->update([
                            'chat_id' => $messageData['chat_id'] ?? $conversation->chat_id,
                            'last_message_at' => now(),
                        ]);
                        $conversationIds[$conversation->id] = true;
                        $storedMessages++;
                    }
                });
                foreach (array_keys($conversationIds) as $conversationId) {
                    ProcessAdminConversation::dispatch(
                        $conversationId,
                        now()->toDateTimeString(),
                        1
                    )->delay(now()->addSeconds(3));
                }
                return [
                    'status' => true,
                    'messages_stored' => $storedMessages,
                ];
            } catch (InvalidArgumentException $e) {
                Log::warning('Invalid webhook payload.', [
                    'message' => $e->getMessage(),
                    'payload' => $request->all(),
                ]);
                throw $e;
            } catch (\Throwable $e) {
                Log::error('Webhook processing failed.', [
                    'message' => $e->getMessage(),
                    'payload' => $request->all(),
                ]);
                throw $e;
            }
        }

    /**
     * Heywave posts a single message per webhook:
     *   { event: "message", instance: "...", data: { fromNumber, body, ... } }
     * Translate it into the legacy multi-message shape the storage loop
     * expects, so both gateway formats work during the cutover.
     *
     * Notes on the mapping:
     * - data.fromNumber is the real phone number; data.from is an internal
     *   @lid identifier and must NOT be used for replies.
     * - Heywave sends no message id, so a deterministic hash provides
     *   idempotency across webhook retries.
     * - Non-"message" events and group chats are mapped to the statuses
     *   shape, which the handlers already skip gracefully.
     */
    private function normalizeHeywave(array $payload): array
    {
        if (!isset($payload['data']) || !array_key_exists('event', $payload) || isset($payload['messages'])) {
            return $payload; // Not a Heywave payload - leave untouched.
        }

        $data = $payload['data'];
        $skip = ['event' => ['type' => 'statuses', 'event' => 'post']];

        if (($payload['event'] ?? null) !== 'message') {
            return $skip;
        }

        if (!empty($data['isGroup'])) {
            return $skip;
        }

        $fromNumber = preg_replace('/[^0-9]/', '', (string) ($data['fromNumber'] ?? ''));
        if ($fromNumber === '') {
            return $skip;
        }

        $type = (string) ($data['type'] ?? 'chat');
        $body = (string) ($data['body'] ?? '');

        return [
            'channel_id' => $payload['instance'] ?? 'heywave',
            'event' => ['type' => 'messages', 'event' => 'post'],
            'messages' => [[
                'id' => 'hw-' . md5(
                    ($payload['instance'] ?? '') . '|' . $fromNumber . '|'
                    . ($data['timestamp'] ?? '') . '|' . $body
                ),
                'from' => $fromNumber,
                'chat_id' => $fromNumber,
                'from_me' => false,
                'type' => $type === 'chat' ? 'text' : $type,
                'timestamp' => $data['timestamp'] ?? now()->timestamp,
                'source' => 'heywave',
                'text' => ['body' => $body !== '' ? $body : null],
                'from_name' => $data['author'] ?? null,
            ]],
        ];
    }
}
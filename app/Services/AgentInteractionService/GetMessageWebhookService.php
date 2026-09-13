<?php

namespace App\Services\AgentInteractionService;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\Jobs\ProcessConversation;
use Illuminate\Support\Facades\Log;
class GetMessageWebhookService
{
    // public function receive(Request $request): array
    //     {
    //         try {
    //             $payload = $request->all();
    //             if (!isset($payload['messages']) || !is_array($payload['messages'])) {
    //                 throw new InvalidArgumentException('Invalid webhook payload.');
    //             }
    //             $channelId = $payload['channel_id'] ?? null;
    //             if (!$channelId) {
    //                 throw new InvalidArgumentException('channel_id is required.');
    //             }
    //             $storedMessages = 0;
    //             $conversationIds = [];
    //             DB::transaction(function () use (
    //                 $payload,
    //                 $channelId,
    //                 &$storedMessages,
    //                 &$conversationIds
    //             ) {
    //                 foreach ($payload['messages'] as $messageData) {
    //                     $sender = $messageData['from'] ?? null;
    //                     if (!$sender) {
    //                         continue;
    //                     }
    //                     $messageId = $messageData['id'] ?? null;
    //                     if (!$messageId) {
    //                         continue;
    //                     }
    //                     $conversation = Conversation::firstOrCreate(
    //                         [
    //                             'sender' => $messageData['from'],
    //                             'channel_id' => $channelId,
    //                         ],
    //                         [
    //                             'chat_id' => $messageData['chat_id'] ?? null,
    //                             'last_message_at' => now(),
    //                             'status' => 'active',
    //                             'processing' => false,
    //                         ]
    //                     );
    //                     Message::updateOrCreate(
    //                         [
    //                             'message_id' => $messageId,
    //                         ],
    //                         [
    //                             'conversation_id' => $conversation->id,
    //                             'sender' => $sender,
    //                             'from_me' => $messageData['from_me'] ?? false,
    //                             'type' => $messageData['type'] ?? null,
    //                             'message_timestamp' => $messageData['timestamp'] ?? null,
    //                             'source' => $messageData['source'] ?? null,
    //                             'chat_id' => $messageData['chat_id'] ?? null,
    //                             'text' => $messageData['text']['body'] ?? null,
    //                             'from_name' => $messageData['from_name'] ?? null,
    //                             'payload' => $messageData,
    //                         ]
    //                     );
    //                     $conversation->update([
    //                         'chat_id' => $messageData['chat_id'] ?? $conversation->chat_id,
    //                         'last_message_at' => now(),
    //                     ]);
    //                     $conversationIds[$conversation->id] = true;
    //                     $storedMessages++;
    //                 }
    //             });
    //             foreach (array_keys($conversationIds) as $conversationId) {
    //                 ProcessConversation::dispatch(
    //                     $conversationId,
    //                     now()->toDateTimeString(),
    //                     1
    //                 )->delay(now()->addSeconds(3));
    //             }
    //             return [
    //                 'status' => true,
    //                 'messages_stored' => $storedMessages,
    //             ];
    //         } catch (InvalidArgumentException $e) {
    //             Log::warning('Invalid webhook payload.', [
    //                 'message' => $e->getMessage(),
    //                 'payload' => $request->all(),
    //             ]);
    //             throw $e;
    //         } catch (\Throwable $e) {
    //             Log::error('Webhook processing failed.', [
    //                 'message' => $e->getMessage(),
    //                 'file' => $e->getFile(),
    //                 'line' => $e->getLine(),
    //                 'payload' => $request->all(),
    //             ]);
    //             throw $e;
    //         }
    //     }
    public function receive(Request $request): array
        {
            try {
                $payload = $request->all();
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
                        // Echoes of our own outgoing messages - do not store or reprocess.
                        if (!empty($messageData['from_me'])) {
                            continue;
                        }
                        // WhatsApp protocol/system events (encryption notices,
                        // sync placeholders) carry no customer content - the
                        // gateway sends them as source "system" / type
                        // "unknown". Storing them makes the agent reply to
                        // messages the customer never sent.
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
}
<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AgentPromptService;
use App\Services\AIAgentService;
use App\Services\ConversationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\WhatsAppService;

class ProcessAdminConversation implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $conversationId,
        public string $scheduledAt,
        public int $clientId
    ) {}

    public function handle(
        ConversationService $conversationService,
        AgentPromptService $agentPromptService,
        AIAgentService $aiAgentService,
        WhatsAppService $whatsAppService,
        \App\Services\Ai\StaffToolHandler $staffTools
    ): void {
        $conversation = DB::transaction(function () {
            $conversation = Conversation::lockForUpdate()
                ->find($this->conversationId);
            if (!$conversation) {
                return null;
            }
            if (!$conversation->last_message_at) {
                return null;
            }
            $lastMessageAt = $conversation->last_message_at->timestamp;
            $scheduledAt = strtotime($this->scheduledAt);
            if ($lastMessageAt > $scheduledAt) {
                return null;
            }
            if ($conversation->last_message_at->gt(now()->subSeconds(3))) {
                return null;
            }
            if ($conversation->processing) {
                return null;
            }
            $conversation->update([
                'processing' => true,
            ]);
            return $conversation;
        });
        if (!$conversation) {
            return;
        }
        try {
            $context = $conversationService->getContext($conversation);
            if (empty($context['current_messages'])) {
                $this->releaseConversation($conversation);
                return;
            }

            // Hard gate: the admin line serves registered staff only. Unknown
            // numbers get a fixed refusal - no AI call, no tokens spent.
            $staff = $staffTools->resolveStaff([
                'whatsapp_number' => $conversation->sender,
            ]);

            if (!$staff) {
                $response = 'This WhatsApp line is for Orvell staff only. '
                    . 'If you are a customer, please contact us on our customer line. Thank you 🙏';

                logger()->warning('Admin line message from unregistered number', [
                    'line' => 'admin',
                    'conversation_id' => $conversation->id,
                    'sender' => $conversation->sender,
                ]);
            } else {
                $prompt = $agentPromptService->getPrompt($this->clientId, 'admin');
                $promptText = $prompt?->prompt_description
                    ?? $agentPromptService->getDefaultPrompt('admin');

                $response = $aiAgentService->generate(
                    $promptText,
                    $context['history'],
                    $context['current_messages'],
                    [
                        'whatsapp_number' => $conversation->sender,
                        'wa_id' => $conversation->chat_id ?? $conversation->sender,
                        'profile_name' => $staff->name,
                        'staff_id' => $staff->id,
                    ],
                    $staffTools
                );
            }
            $aiMessage = Message::create([
                'conversation_id' => $conversation->id,
                'message_id' => (string) Str::uuid(),
                'sender' => $conversation->sender,
                'recipient' => $conversation->sender,
                'sender_type' => 'assistant',
                'from_me' => true,
                'type' => 'text',
                'message_timestamp' => now()->timestamp,
                'source' => 'ai',
                'chat_id' => $conversation->chat_id,
                'text' => $response,
                'from_name' => null,
                'payload' => [
                    'source' => 'ai',
                    'response' => $response,
                ],
                'processed_at' => now(),
            ]);
            $whatsAppService->sendAdminText(
                $conversation->chat_id,
                $response
            );
            $currentMessageIds = collect(
                $context['current_messages']
            )->pluck('id');
            Message::where('conversation_id', $conversation->id)
                ->whereNull('processed_at')
                ->where('from_me', false)
                ->whereIn('id', $currentMessageIds)
                ->update([
                    'processed_at' => now(),
                ]);
            $this->releaseConversation($conversation);
            logger()->info('Conversation processed successfully', [
                'line' => 'admin',
                'conversation_id' => $conversation->id,
                'sender' => $conversation->sender,
                'ai_message_id' => $aiMessage->id,
            ]);
        } catch (\Throwable $exception) {
            $this->releaseConversation($conversation);
            logger()->error('Conversation processing failed', [
                'line' => 'admin',
                'conversation_id' => $conversation->id,
                'sender' => $conversation->sender,
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            throw $exception;
        }
    }

    private function releaseConversation(
        Conversation $conversation
    ): void {
        $conversation->update([
            'processing' => false,
        ]);
    }
}
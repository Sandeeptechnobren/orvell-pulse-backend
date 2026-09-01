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

class ProcessConversation implements ShouldQueue
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
        WhatsAppService $whatsAppService
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
            $prompt = $agentPromptService->getCustomerPrompt(
                $this->clientId
            );
            $response = $aiAgentService->generate(
                $prompt->prompt_description,
                $context['history'],
                $context['current_messages']
            );
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
            $whatsAppService->sendText(
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
                'conversation_id' => $conversation->id,
                'sender' => $conversation->sender,
                'ai_message_id' => $aiMessage->id,
            ]);
        } catch (\Throwable $exception) {
            $this->releaseConversation($conversation);
            logger()->error('Conversation processing failed', [
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
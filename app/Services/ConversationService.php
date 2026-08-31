<?php

namespace App\Services;

use App\Models\Conversation;

class ConversationService
{
    public function getContext(
        Conversation $conversation,
        int $historyLimit = 20
    ): array {
        $currentMessages = $conversation->messages()
            ->whereNull('processed_at')
            ->where('from_me', false)
            ->orderBy('id')
            ->get();

        if ($currentMessages->isEmpty()) {
            return [
                'conversation_id' => $conversation->id,
                'sender' => $conversation->sender,
                'channel_id' => $conversation->channel_id,
                'history' => [],
                'current_messages' => [],
            ];
        }

        $firstCurrentMessageId = $currentMessages->first()->id;

        $history = $conversation->messages()
            ->where('id', '<', $firstCurrentMessageId)
            ->orderBy('id', 'desc')
            ->limit($historyLimit)
            ->get()
            ->reverse()
            ->values();

        return [
            'conversation_id' => $conversation->id,
            'sender' => $conversation->sender,
            'channel_id' => $conversation->channel_id,

            'history' => $this->formatMessages($history),

            'current_messages' => $this->formatMessages($currentMessages),
        ];
    }

    private function formatMessages($messages): array
    {
       return $messages->map(function ($message) {
            return [
                'id' => $message->id,
                'role' => $message->from_me ? 'assistant' : 'user',
                'content' => $message->text,
                'timestamp' => $message->message_timestamp,
            ];
        })->values()->toArray();
    }
}
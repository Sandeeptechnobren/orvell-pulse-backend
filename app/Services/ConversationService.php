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
       return $messages
        // Never feed fallback/error texts back as history: the model imitates
        // its own past replies, so one stored placeholder poisons the whole
        // conversation into repeating it forever.
        ->reject(function ($message) {
            return $message->from_me && str_contains(
                (string) $message->text,
                'configuration is currently in progress'
            );
        })
        ->map(function ($message) {
            $text = trim((string) $message->text);

            // Media, stickers, reactions etc. arrive with no text. The API
            // rejects empty content, so substitute a readable placeholder the
            // agent can respond to gracefully.
            if ($text === '') {
                $text = $message->from_me
                    ? '[automated message]'
                    : '[The customer sent a non-text message (photo, voice note, or sticker) that cannot be viewed here. If context is unclear, politely ask them to send it as a text message.]';
            }

            return [
                'id' => $message->id,
                'role' => $message->from_me ? 'assistant' : 'user',
                'content' => $text,
                'timestamp' => $message->message_timestamp,
            ];
        })->values()->toArray();
    }
}
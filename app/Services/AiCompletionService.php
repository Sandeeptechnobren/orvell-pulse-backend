<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AiCompletionService
 *
 * Pluggable AI / LLM completion service for Orvell Pulse WhatsApp Agents.
 * Supports OpenAI (GPT-4o / GPT-4o-mini), Google Gemini, and Mock provider for testing/offline.
 *
 * Enforces:
 *  - Strict short timeout (default 5 seconds) for real-time WhatsApp responsiveness.
 *  - Safe failure handling: never throws unhandled exceptions into the WhatsApp webhook.
 *  - Zero secret leakage: API keys and sensitive tokens are never logged.
 */
class AiCompletionService
{
    protected string $provider;
    protected int $timeout;
    protected int $maxTokens;
    protected float $temperature;

    public function __construct()
    {
        $this->provider    = config('ai.provider', 'openai');
        $this->timeout     = (int) config('ai.timeout', 5);
        $this->maxTokens   = (int) config('ai.max_tokens', 400);
        $this->temperature = (float) config('ai.temperature', 0.5);
    }

    /**
     * Generate an AI completion using the configured provider.
     *
     * @param string $systemPrompt Complete 6-layer system instruction.
     * @param array $conversationHistory Array of recent messages: [['role' => 'user'|'assistant', 'content' => string]].
     * @param string $userMessage Current user/customer input message.
     * @param array $options Provider-specific overrides (model, temperature, etc.).
     * @return string|null Generated assistant reply or null on failure.
     */
    public function complete(string $systemPrompt, array $conversationHistory, string $userMessage, array $options = []): ?string
    {
        $provider = $options['provider'] ?? $this->provider;

        if ($provider === 'disabled') {
            Log::info('[ai] provider disabled; skipping LLM call');
            return null;
        }

        if ($provider === 'mock') {
            return $this->mockCompletion($systemPrompt, $userMessage);
        }

        Log::info('[ai] request started', [
            'provider'       => $provider,
            'history_count'  => count($conversationHistory),
            'message_length' => mb_strlen($userMessage),
        ]);

        try {
            return match ($provider) {
                'gemini' => $this->callGemini($systemPrompt, $conversationHistory, $userMessage, $options),
                default  => $this->callOpenAi($systemPrompt, $conversationHistory, $userMessage, $options),
            };
        } catch (\Throwable $e) {
            Log::warning('[ai] provider failure', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Execute OpenAI Chat Completions API.
     */
    protected function callOpenAi(string $systemPrompt, array $conversationHistory, string $userMessage, array $options = []): ?string
    {
        $apiKey  = $options['api_key'] ?? config('ai.openai.api_key');
        $baseUrl = rtrim((string) ($options['base_url'] ?? config('ai.openai.base_url', 'https://api.openai.com/v1')), '/');
        $model   = $options['model'] ?? config('ai.openai.model', 'gpt-4o-mini');

        if (empty($apiKey)) {
            Log::info('[ai] OpenAI API key not configured; falling back to deterministic engine');
            return null;
        }

        $messages = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        foreach ($conversationHistory as $msg) {
            if (!empty($msg['role']) && !empty($msg['content'])) {
                $messages[] = [
                    'role'    => in_array($msg['role'], ['user', 'assistant'], true) ? $msg['role'] : 'user',
                    'content' => (string) $msg['content'],
                ];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $response = Http::withoutVerifying()
            ->connectTimeout(3)
            ->timeout($this->timeout)
            ->withToken($apiKey)
            ->asJson()
            ->post("{$baseUrl}/chat/completions", [
                'model'       => $model,
                'messages'    => $messages,
                'max_tokens'  => $options['max_tokens'] ?? $this->maxTokens,
                'temperature' => $options['temperature'] ?? $this->temperature,
            ]);

        if (!$response->successful()) {
            Log::warning('[ai] OpenAI API non-200 response', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 200),
            ]);
            return null;
        }

        $reply = $response->json('choices.0.message.content');

        if ($reply && is_string($reply)) {
            Log::info('[ai] provider response received', ['provider' => 'openai', 'length' => mb_strlen($reply)]);
            return trim($reply);
        }

        return null;
    }

    /**
     * Execute Google Gemini generateContent API.
     */
    protected function callGemini(string $systemPrompt, array $conversationHistory, string $userMessage, array $options = []): ?string
    {
        $apiKey  = $options['api_key'] ?? config('ai.gemini.api_key');
        $baseUrl = rtrim((string) ($options['base_url'] ?? config('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta')), '/');
        $model   = $options['model'] ?? config('ai.gemini.model', 'gemini-1.5-flash');

        if (empty($apiKey)) {
            Log::info('[ai] Gemini API key not configured; falling back to deterministic engine');
            return null;
        }

        $contents = [];

        foreach ($conversationHistory as $msg) {
            if (!empty($msg['role']) && !empty($msg['content'])) {
                $contents[] = [
                    'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => (string) $msg['content']]],
                ];
            }
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $userMessage]],
        ];

        $url = "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'contents' => $contents,
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? $this->maxTokens,
                'temperature'     => $options['temperature'] ?? $this->temperature,
            ],
        ];

        $response = Http::withoutVerifying()
            ->connectTimeout(3)
            ->timeout($this->timeout)
            ->asJson()
            ->post($url, $payload);

        if (!$response->successful()) {
            Log::warning('[ai] Gemini API non-200 response', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 200),
            ]);
            return null;
        }

        $reply = $response->json('candidates.0.content.parts.0.text');

        if ($reply && is_string($reply)) {
            Log::info('[ai] provider response received', ['provider' => 'gemini', 'length' => mb_strlen($reply)]);
            return trim($reply);
        }

        return null;
    }

    /**
     * Mock AI response generation for offline testing.
     */
    protected function mockCompletion(string $systemPrompt, string $userMessage): string
    {
        Log::info('[ai] mock completion executed');
        return "🤖 [AI Assistant]: Thank you for contacting Orvell Wholesale. How may I assist you with our garment bale catalog today?";
    }
}

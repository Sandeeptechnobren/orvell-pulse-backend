<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class AIAgentService
{
    public function generate(
        string $systemPrompt,
        array $history,
        array $currentMessages
    ): string {
        if (!$this->isConfigured()) {
            return $this->defaultResponse();
        }

        $input = [];

        foreach ($history as $message) {
            $input[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        foreach ($currentMessages as $message) {
            $input[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $response = OpenAI::responses()->create([
            'model' => config('openai.model', env('OPENAI_MODEL')),
            'instructions' => $systemPrompt,
            'input' => $input,
        ]);

        $output = $response->outputText;

        if (!$output) {
            throw new RuntimeException(
                'OpenAI returned an empty response.'
            );
        }

        return $output;
    }

    private function isConfigured(): bool
    {
        return !empty(config('openai.api_key'));
    }

    private function defaultResponse(): string
    {
        return 'Thank you for your message. Our AI assistant configuration is currently in progress. We will be able to assist you shortly.';
    }
}
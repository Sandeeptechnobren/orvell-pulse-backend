<?php

// namespace App\Services;

// use Anthropic\Client;
// use RuntimeException;

// class AIAgentService
// {
//     public function generate(
//         string $systemPrompt,
//         array $history,
//         array $currentMessages
//     ): string {
//         if (!$this->isConfigured()) {
//             return $this->defaultResponse();
//         }
//         $messages = [];
//         foreach ($history as $message) {
//             $messages[] = [
//                 'role' => $message['role'],
//                 'content' => $message['content'],
//             ];
//         }
//         foreach ($currentMessages as $message) {
//             $messages[] = [
//                 'role' => $message['role'],
//                 'content' => $message['content'],
//             ];
//         }
//         $client = new Client(apiKey: config('ai.anthropic.api_key'));
//         $response = $client->messages->create(
//             model: config('ai.anthropic.model', 'claude-opus-5'),
//             maxTokens: 16000,
//             system: [
//                 ['type' => 'text', 'text' => $systemPrompt],
//             ],
//             messages: $messages,
//         );
//         if ($response->stopReason === 'refusal') {
//             throw new RuntimeException(
//                 'Claude declined to respond to this request.'
//             );
//         }
//         $output = '';
//         foreach ($response->content as $block) {
//             if ($block->type === 'text') {
//                 $output .= $block->text;
//             }
//         }
//         if ($output === '') {
//             throw new RuntimeException(
//                 'Claude returned an empty response.'
//             );
//         }
//         return $output;
//     }

//     private function isConfigured(): bool
//     {
//         return !empty(config('ai.anthropic.api_key'));
//     }

//     private function defaultResponse(): string
//     {
//         return 'Thank you for your message. Our AI assistant configuration is currently in progress. We will be able to assist you shortly.';
//     }
// }

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\ToolUseBlock;
use App\Services\Ai\CustomerToolHandler;
use RuntimeException;

class AIAgentService
{
    private const MAX_TOOL_ROUNDS = 8;

    public function __construct(
        private readonly CustomerToolHandler $toolHandler
    ) {
    }

    public function generate(
        string $systemPrompt,
        array $history,
        array $currentMessages,
        array $context = []
    ): string {
        if (!$this->isConfigured()) {
            return $this->defaultResponse();
        }

        $messages = [];
        foreach ($history as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }
        foreach ($currentMessages as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $client = new Client(apiKey: config('ai.anthropic.api_key'));
        $tools = $this->toolHandler->definitions();

        $response = $client->messages->create(
            model: config('ai.anthropic.model', 'claude-opus-5'),
            maxTokens: 16000,
            system: [
                ['type' => 'text', 'text' => $systemPrompt],
            ],
            tools: $tools,
            messages: $messages,
        );

        $rounds = 0;
        while ($response->stopReason === 'tool_use') {
            if (++$rounds > self::MAX_TOOL_ROUNDS) {
                throw new RuntimeException('Tool loop exceeded maximum rounds.');
            }

            $toolResults = [];
            foreach ($response->content as $block) {
                if ($block instanceof ToolUseBlock) {
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $block->id,
                        'content' => $this->toolHandler->execute(
                            $block->name,
                            $block->input,
                            $context
                        ),
                    ];
                }
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $toolResults];

            $response = $client->messages->create(
                model: config('ai.anthropic.model', 'claude-opus-5'),
                maxTokens: 16000,
                system: [
                    ['type' => 'text', 'text' => $systemPrompt],
                ],
                tools: $tools,
                messages: $messages,
            );
        }

        if ($response->stopReason === 'refusal') {
            throw new RuntimeException('Claude declined to respond to this request.');
        }

        $output = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $output .= $block->text;
            }
        }

        if ($output === '') {
            throw new RuntimeException('Claude returned an empty response.');
        }

        return $output;
    }

    private function isConfigured(): bool
    {
        return !empty(config('ai.anthropic.api_key'));
    }

    private function defaultResponse(): string
    {
        return 'Thank you for your message. Our AI assistant configuration is currently in progress. We will be able to assist you shortly.';
    }
}
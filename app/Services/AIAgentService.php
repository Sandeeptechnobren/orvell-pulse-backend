<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\ToolUseBlock;
use App\Services\Ai\CustomerToolHandler;
use Illuminate\Support\Facades\Log;
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

        // Token control: only the most recent turns ride along. Older context
        // rarely matters for a counter-service chat and costs on every call.
        $historyLimit = (int) config('ai.anthropic.history_limit', 30);
        if (count($history) > $historyLimit) {
            $history = array_slice($history, -$historyLimit);
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

        // Cache breakpoint on the system prompt: tools + system render before
        // messages, so this one stable prefix is shared across EVERY customer
        // conversation and every tool round. The top-level cacheControl adds a
        // second breakpoint at the end of the message history for reuse across
        // turns and within the tool loop.
        $system = [
            [
                'type' => 'text',
                'text' => $systemPrompt,
                'cacheControl' => ['type' => 'ephemeral'],
            ],
        ];

        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];

        $response = $this->request($client, $system, $tools, $messages, $usage);

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

            $response = $this->request($client, $system, $tools, $messages, $usage);
        }

        Log::info('AI usage', [
            'whatsapp_number' => $context['whatsapp_number'] ?? null,
            'tool_rounds' => $rounds,
            'input_tokens' => $usage['input'],
            'output_tokens' => $usage['output'],
            'cache_read_tokens' => $usage['cache_read'],
            'cache_write_tokens' => $usage['cache_write'],
        ]);

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

    private function request(
        Client $client,
        array $system,
        array $tools,
        array $messages,
        array &$usage
    ) {
        $response = $client->messages->create(
            model: config('ai.anthropic.model', 'claude-opus-5'),
            maxTokens: (int) config('ai.anthropic.max_reply_tokens', 1024),
            system: $system,
            tools: $tools,
            messages: $messages,
            outputConfig: ['effort' => config('ai.anthropic.effort', 'low')],
            cacheControl: ['type' => 'ephemeral'],
        );

        $usage['input'] += $response->usage->inputTokens ?? 0;
        $usage['output'] += $response->usage->outputTokens ?? 0;
        $usage['cache_read'] += $response->usage->cacheReadInputTokens ?? 0;
        $usage['cache_write'] += $response->usage->cacheCreationInputTokens ?? 0;

        return $response;
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

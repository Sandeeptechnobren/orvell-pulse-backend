<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class AgentService
{
    public function __construct(
        private MessageService $messages,
        private AgentPromptService $prompts,
    ) {
    }

    public function initialise(string $agentType)
    {
        return $this->messages->initialiseAgent($agentType);
    }

    public function savePrompt(string $promptFor, string $description)
    {
        return $this->prompts->storeOrUpdatePrompt(
            clientId: $this->clientId(),
            promptFor: $promptFor,
            promptDescription: $description,
            userId: Auth::id(),
        );
    }

    /**
     * Falls back to the built-in prompt so the caller always gets usable text.
     */
    public function prompt(string $promptFor): array
    {
        $prompt = $this->prompts->getPrompt($this->clientId(), $promptFor);

        return [
            'prompt_for'         => $promptFor,
            'prompt_description' => $prompt->prompt_description ?? $this->prompts->getDefaultPrompt($promptFor),
            'is_custom'          => ! empty($prompt?->prompt_description),
            'prompt'             => $prompt,
        ];
    }

    private function clientId(): int
    {
        $user = Auth::user();

        return (int) ($user?->client_id ?? $user?->id ?? 1);
    }
}

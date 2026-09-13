<?php

namespace App\Services;

use App\Models\agentPrompt;
use RuntimeException;

class AgentPromptService
{
    public function getCustomerPrompt(int $clientId): agentPrompt
    {
        $prompt = agentPrompt::query()
            ->where('client_id', $clientId)
            ->where('prompt_for', 'customer')
            ->latest('updated_at')
            ->first();

        if (!$prompt) {
            throw new RuntimeException(
                "Customer AI prompt not found for client {$clientId}."
            );
        }

        return $prompt;
    }
}
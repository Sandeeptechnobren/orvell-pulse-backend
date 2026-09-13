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

    public function getPrompt(int $clientId, string $promptFor): ?agentPrompt
    {
        return agentPrompt::query()
            ->where('client_id', $clientId)
            ->where('prompt_for', $promptFor)
            ->latest('updated_at')
            ->first();
    }

    public function storeOrUpdatePrompt(
        int $clientId,
        string $promptFor,
        string $promptDescription,
        ?int $userId = null
    ): agentPrompt {
        $prompt = $this->getPrompt($clientId, $promptFor);

        if ($prompt) {
            $prompt->update([
                'prompt_description' => $promptDescription,
                'user_id' => $userId ?? $prompt->user_id,
            ]);

            return $prompt->fresh();
        }

        return agentPrompt::create([
            'client_id' => $clientId,
            'prompt_for' => $promptFor,
            'prompt_description' => $promptDescription,
            'user_id' => $userId,
        ]);
    }

    public function getDefaultPrompt(string $promptFor): string
    {
        if ($promptFor === 'admin') {
            return 'You are the Orvell admin assistant. Help staff with internal operations, '
                . 'reporting, and administrative questions. Be concise and professional.';
        }

        return 'You are PULSE, the WhatsApp assistant for Orvell, a wholesale bale importer. '
            . 'Help customers register, check stock, place order requests, and track their '
            . 'orders using your tools. Be warm, concise, and never invent data or prices.';
    }
}
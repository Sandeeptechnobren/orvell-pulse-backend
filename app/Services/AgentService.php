<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class AgentService
{
    public function __construct(
        private MessageService $messages,
        private AgentPromptService $prompts,
    ) {
    }

public function initialise(string $agentType)
    {
        if($agentType=="customer"){
        $customer_token = config('services.customer_whapi.token');
        $response = Http::withHeaders([
            'accept' => 'application/json',
            'authorization' => 'Bearer ' . $customer_token,
        ])->get('https://gate.whapi.cloud/users/login', [
            'wakeup' => 'true',
        ]);
        if ($response->successful()) {
            $data = $response->json();
            return $data;
        }
        return [
            'success' => false,
            'status' => $response->status(),
            'message' => $response->body(),
        ];
        }
        else{
        // $admin_token = config('services.admin_whapi.token');
        // $response = Http::withHeaders([
        //     'accept' => 'application/json',
        //     'authorization' => 'Bearer ' . $admin_token,
        // ])->get('https://gate.whapi.cloud/users/login', [
        //     'wakeup' => 'true',
        // ]);
        // if ($response->successful()) {
        //     $data = $response->json();
        //     return $data;
        // }
        return [
            'success' => false,
            // 'status' => $response->status(),
            // 'message' => $response->body(),
            'message'=>"Admin Configuration in Progress! Please try again a later."
        ];
        }
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

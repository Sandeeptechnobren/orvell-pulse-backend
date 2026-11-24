<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class AgentService
{
    public function __construct(
        private AgentPromptService $prompts,
    ) {
    }

public function initialise(string $agentType)
    {
        if($agentType=="customer"){
        $response = Http::withHeaders([
        'accept' => 'image/png',
            ])->get(
                config('services.heywave.base_url') . '/instance/qrpng/'
                    . config('services.heywave.customer.instance_id'),
                ['token' => config('services.heywave.customer.token')]
            );

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', $response->header('Content-Type', 'image/png'))
                    ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
            }

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch QR image',
                'error' => $response->body(),
            ], $response->status());
        }
        else{
        $response = Http::withHeaders([
        'accept' => 'image/png',
            ])->get(
                config('services.heywave.base_url') . '/instance/qrpng/'
                    . config('services.heywave.admin.instance_id'),
                ['token' => config('services.heywave.admin.token')]
            );

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', $response->header('Content-Type', 'image/png'))
                    ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
            }

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch QR image',
                'error' => $response->body(),
            ], $response->status());
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

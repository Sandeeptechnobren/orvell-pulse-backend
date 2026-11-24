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
        // $customer_token = config('services.customer_whapi.token');
        // $response = Http::withHeaders([
        //     'accept' => 'application/json',
        //     'authorization' => 'Bearer ' . $customer_token,
        // ])->get('https://gate.whapi.cloud/users/login', [
        //     'wakeup' => 'true',
        // ]);
        // if ($response->successful()) {
        //     $data = $response->json();
        //     return $data;
        // }
        // return [
        //     'success' => false,
        //     'status' => $response->status(),
        //     'message' => $response->body(),
        // ];
        $response = Http::withHeaders([
        'accept' => 'image/png',
            ])->get('https://heywave.kineticsoftwaresolution.com/api/instance/qrpng/customer_line', [
                'token' => 'inst_41723cee9d0eef70c26abf3dbeb2348039ad0c294839e36b',
            ]);

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
        // $admin_token = config('services.admin_whapi.token');
        // // $response = Http::withHeaders([
        // //     'accept' => 'application/json',
        // //     'authorization' => 'Bearer ' . $admin_token,
        // // ])->get('https://gate.whapi.cloud/users/login', [
        // //     'wakeup' => 'true',
        // // ]);
        // $response = Http::withHeaders([
        //     'accept' => 'application/json',
        //     // 'authorization' => 'Bearer ' . $admin_token,
        // ])->get('https://heywave.kineticsoftwaresolution.com/api/instance/qrpng/admin_line', [
        //     'token' => 'inst_d74a1c6fca3aad8bcc97efb40f1c7a0ca2212bdc7b39afbb',
        // ]);
        // if ($response->successful()) {
        //     $data = $response->json();
        //     return $data;
        // }
        // return [
        //     'success' => false,
        //     'status' => $response->status(),
        //     'message' => $response->body(),
        // ];
        $response = Http::withHeaders([
        'accept' => 'image/png',
            ])->get('https://heywave.kineticsoftwaresolution.com/api/instance/qrpng/admin_line', [
                'token' => 'inst_d74a1c6fca3aad8bcc97efb40f1c7a0ca2212bdc7b39afbb',
            ]);

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

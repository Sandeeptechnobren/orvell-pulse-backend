<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppService
{
    public function sendText(
        string $chatId,
        string $message
    ): array {
        $token = config('services.customer_whapi.token');

        if (!$token) {
            throw new RuntimeException(
                'WHAPI_TOKEN is not configured.'
            );
        }

        $response = Http::baseUrl(
            config('services.whapi.base_url')
        )
            ->withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post('/messages/text', [
                'to' => $chatId,
                'body' => $message,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'WhatsApp message failed: ' .
                $response->body()
            );
        }

        return $response->json();
    }
}
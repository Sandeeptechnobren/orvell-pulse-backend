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

    /**
     * Send a document (e.g. a PDF invoice) as an in-chat attachment. The file
     * travels as base64 - it is never exposed on a public URL.
     */
    public function sendDocument(
        string $chatId,
        string $binaryContent,
        string $filename,
        ?string $caption = null,
        string $mimeType = 'application/pdf'
    ): array {
        $token = config('services.customer_whapi.token');

        if (!$token) {
            throw new RuntimeException(
                'WHAPI_TOKEN is not configured.'
            );
        }

        $payload = [
            'to' => $chatId,
            'media' => 'data:' . $mimeType . ';name=' . $filename . ';base64,'
                . base64_encode($binaryContent),
            'filename' => $filename,
        ];

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        $response = Http::baseUrl(
            config('services.whapi.base_url')
        )
            ->withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->post('/messages/document', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'WhatsApp document failed: ' .
                $response->body()
            );
        }

        return $response->json();
    }
}
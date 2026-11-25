<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Outbound WhatsApp via Heywave. Each line (customer / admin) is a Heywave
 * instance addressed by instance id (URL) + instance token (request body).
 */
class WhatsAppService
{
    public function sendText(
        string $chatId,
        string $message,
        string $channel = 'customer'
    ): array {
        [$instanceId, $token] = $this->instance($channel);

        $response = Http::baseUrl(config('services.heywave.base_url'))
            ->acceptJson()
            ->timeout(30)
            ->post("/instance/send/{$instanceId}", [
                'token' => $token,
                'number' => $this->normalizeNumber($chatId),
                'message' => $message,
            ]);

        return $this->assertSuccess($response, 'WhatsApp message failed');
    }

    public function sendAdminText(
        string $chatId,
        string $message
    ): array {
        return $this->sendText($chatId, $message, 'admin');
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
        string $mimeType = 'application/pdf',
        string $channel = 'customer'
    ): array {
        [$instanceId, $token] = $this->instance($channel);

        $payload = [
            'token' => $token,
            'number' => $this->normalizeNumber($chatId),
            // Heywave's docs show a data: URI here, but in practice the API
            // treats the field as raw base64 - the prefix corrupts the file.
            'base64' => base64_encode($binaryContent),
            'mimetype' => $mimeType,
            'filename' => $filename,
        ];

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        $response = Http::baseUrl(config('services.heywave.base_url'))
            ->acceptJson()
            ->timeout(60)
            ->post("/instance/send-media/{$instanceId}", $payload);

        return $this->assertSuccess($response, 'WhatsApp document failed');
    }

    /**
     * Resolve instance id + token for a line ('customer' or 'admin').
     */
    private function instance(string $channel): array
    {
        $instanceId = config("services.heywave.{$channel}.instance_id");
        $token = config("services.heywave.{$channel}.token");

        if (!$instanceId || !$token) {
            throw new RuntimeException(
                "Heywave is not configured for the {$channel} line - set HEYWAVE_" .
                strtoupper($channel) . '_INSTANCE_ID and HEYWAVE_' .
                strtoupper($channel) . '_TOKEN.'
            );
        }

        return [$instanceId, $token];
    }

    /**
     * Heywave expects a bare international number (e.g. 919876543210), while
     * stored chat ids may look like 919876543210@s.whatsapp.net.
     */
    private function normalizeNumber(string $chatId): string
    {
        // Privacy-shielded chats are addressed by their @lid identity - the
        // suffix must survive, Heywave needs it to route the message.
        if (str_ends_with($chatId, '@lid')) {
            $digits = preg_replace('/[^0-9]/', '', (string) strstr($chatId, '@', true));

            return $digits . '@lid';
        }

        $bare = str_contains($chatId, '@')
            ? strstr($chatId, '@', true)
            : $chatId;

        return preg_replace('/[^0-9]/', '', $bare);
    }

    private function assertSuccess($response, string $errorPrefix): array
    {
        $json = $response->json() ?? [];

        if ($response->failed() || !($json['success'] ?? false)) {
            throw new RuntimeException(
                $errorPrefix . ': ' . $response->body()
            );
        }

        return $json;
    }
}

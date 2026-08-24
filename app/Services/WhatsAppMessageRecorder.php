<?php

namespace App\Services;

use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the message ledger. Recording never throws — a ledger failure must not
 * stop a reply going out.
 */
class WhatsAppMessageRecorder
{
    public function inbound(string $from, ?string $to, ?string $body, array $meta = []): ?WhatsappMessage
    {
        return $this->write([
            'sender_wa_id'    => $from,
            'recipient_wa_id' => $to ?: 'system',
            'direction'       => 'inbound',
            'message_body'    => $body,
            'message_type'    => $meta['type'] ?? 'text',
            'media_url'       => $meta['media_url'] ?? null,
            'status'          => $meta['status'] ?? 'received',
        ], $meta, $this->inboundKey($from, $body, $meta));
    }

    public function outbound(string $to, ?string $body, bool $delivered, array $meta = []): ?WhatsappMessage
    {
        return $this->write([
            'sender_wa_id'    => 'system',
            'recipient_wa_id' => $to,
            'direction'       => 'outbound',
            'message_body'    => $body,
            'message_type'    => $meta['type'] ?? 'text',
            'media_url'       => $meta['media_url'] ?? null,
            'status'          => $meta['status'] ?? ($delivered ? 'delivered' : 'failed'),
        ], $meta, $meta['key'] ?? null);
    }

    private function inboundKey(string $from, ?string $body, array $meta): ?string
    {
        if (! empty($meta['key'])) {
            return (string) $meta['key'];
        }

        if (($meta['timestamp'] ?? null) === null) {
            return null;
        }

        return 'in:'.md5(($meta['instance_name'] ?? '').'|'.$from.'|'.$meta['timestamp'].'|'.$body);
    }

    private function write(array $attributes, array $meta, ?string $key): ?WhatsappMessage
    {
        $agent = $meta['instance'] ?? null;

        $attributes['client_id']             = $meta['client_id'] ?? $agent?->client_id;
        $attributes['company_id']            = $meta['company_id'] ?? null;
        $attributes['chatterly_instance_id'] = $meta['instance_name'] ?? $agent?->instance_id;
        $attributes['idempotency_key']       = $key;
        $attributes['uuid']                  = (string) Str::uuid();

        try {
            // The unique key lets a repeated delivery collapse into the existing row.
            if ($key !== null) {
                return WhatsappMessage::firstOrCreate(['idempotency_key' => $key], $attributes);
            }

            return WhatsappMessage::create($attributes);
        } catch (\Throwable $e) {
            Log::warning('[wa-ledger] could not record message', [
                'direction' => $attributes['direction'] ?? '?',
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }
}

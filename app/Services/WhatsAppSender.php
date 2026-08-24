<?php

namespace App\Services;

use App\Models\AgentDetails;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppSender
{
    /**
     * The gateway answers 500 with this body while still delivering the message.
     * Treating it as a failure is why sends used to report false negatives.
     */
    private const BENIGN_GATEWAY_ERRORS = [
        "Cannot read properties of undefined (reading 'id')",
    ];

    public function __construct(private WhatsAppMessageRecorder $ledger)
    {
    }

    public function reply(array $payload, string $message): array
    {
        $to = data_get($payload, 'data.from') ?? data_get($payload, 'from') ?? '';

        $agent = app(ChatterlyService::class)->resolveAgent(
            data_get($payload, 'instance'),
            data_get($payload, 'instance_id')
        );

        return $this->dispatch($agent, (string) $to, $message);
    }

    public function toCustomer(string $to, string $message, ?int $clientId = null): array
    {
        return $this->send($to, $message, 'customer', $clientId);
    }

    public function toAdmin(string $to, string $message, ?int $clientId = null): array
    {
        return $this->send($to, $message, 'admin', $clientId);
    }

    public function send(string $to, string $message, string $agentType = 'customer', ?int $clientId = null): array
    {
        $agent = AgentDetails::query()
            ->where('agent_type', $agentType)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('id')
            ->first();

        if (! $agent) {
            return $this->fail($to, "No '{$agentType}' WhatsApp instance is registered.");
        }

        return $this->dispatch($agent, $to, $message);
    }

    public function viaAgent(?AgentDetails $agent, string $to, string $message): array
    {
        return $this->dispatch($agent, $to, $message);
    }

    /** A chat id is passed through as-is; anything else is reduced to digits. */
    public static function normaliseRecipient(string $to): string
    {
        $to = trim($to);

        if ($to === '' || Str::contains($to, '@')) {
            return $to;
        }

        $digits = preg_replace('/\D+/', '', $to) ?? '';

        return $digits === '' ? $to : $digits.'@c.us';
    }

    private function dispatch(?AgentDetails $agent, string $to, string $message): array
    {
        $to = self::normaliseRecipient($to);

        if ($to === '' || trim($message) === '') {
            return $this->fail($to, 'Recipient and message are both required.');
        }

        if (! $agent?->name || ! $agent?->token) {
            return $this->fail($to, 'The WhatsApp instance has no name or token stored.', null, $message);
        }

        $base = rtrim((string) config('chatterly.base_url'), '/');

        if ($base === '') {
            return $this->fail($to, 'CHATTERLY_BASE_URL is not configured.', $agent, $message);
        }

        try {
            $response = Http::withoutVerifying()
                ->connectTimeout(5)
                ->timeout(10)
                ->asJson()
                ->post(
                    $base.'/api/instance/send/'.urlencode($agent->name).'?token='.urlencode($agent->token),
                    ['number' => $to, 'message' => $message]
                );
        } catch (\Throwable $e) {
            Log::error('[wa-send] transport failure', ['to' => $to, 'error' => $e->getMessage()]);

            return $this->fail($to, $e->getMessage(), $agent, $message);
        }

        $status = $response->status();
        $body   = mb_substr($response->body(), 0, 300);
        $note   = null;

        $delivered = $response->successful();

        if (! $delivered && $this->isBenignGatewayError($body)) {
            $delivered = true;
            $note      = "Gateway returned {$status} but the message was delivered.";
        }

        Log::info('[wa-send]', [
            'to'        => $to,
            'instance'  => $agent->name,
            'status'    => $status,
            'delivered' => $delivered,
            'body'      => $body,
        ]);

        $this->ledger->outbound($to, $message, $delivered, [
            'instance'      => $agent,
            'instance_name' => $agent->name,
        ]);

        return [
            'delivered' => $delivered,
            'status'    => $status,
            'to'        => $to,
            'instance'  => $agent->name,
            'note'      => $note ?? ($delivered ? null : $body),
        ];
    }

    private function isBenignGatewayError(string $body): bool
    {
        foreach (self::BENIGN_GATEWAY_ERRORS as $needle) {
            if (Str::contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $to, string $reason, ?AgentDetails $agent = null, ?string $message = null): array
    {
        Log::warning('[wa-send] not sent', ['to' => $to, 'reason' => $reason]);

        if ($message !== null && $to !== '') {
            $this->ledger->outbound($to, $message, false, [
                'instance'      => $agent,
                'instance_name' => $agent?->name,
            ]);
        }

        return [
            'delivered' => false,
            'status'    => 0,
            'to'        => $to,
            'instance'  => $agent?->name,
            'note'      => $reason,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\AgentDetails;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin adapter over the Chatterly / Heywave WhatsApp gateway.
 *
 *  - resolveAgent(): given the instance a message arrived on (name or id), find the matching
 *    `agentDetails` row created during QR provisioning — this tells us whether the number is
 *    the ADMIN agent or the CUSTOMER agent.
 *  - sendText(): send a reply back to a WhatsApp number through that instance.
 *
 * The send route is config-driven (CHATTERLY_SEND_PATH) so Heywave's exact endpoint can be set
 * without code changes; everything else (token, instance, base url) is wired. Always graceful.
 */
class ChatterlyService
{
    private string $baseUrl;

    private string $adminToken;

    private string $sendPath;

    public function __construct()
    {
        $this->baseUrl    = rtrim((string) env('CHATTERLY_BASE_URL', 'https://chatterly.easycoders.in'), '/');
        $this->adminToken = (string) env('CHATTERLY_ADMIN_TOKEN', '');
        $this->sendPath   = (string) env('CHATTERLY_SEND_PATH', '/api/instance/sendText');
    }

    /** Resolve which agent (admin|customer) owns a Chatterly instance. */
    public function resolveAgent(?string $instanceName, ?string $instanceId): ?AgentDetails
    {
        if ($instanceName) {
            $row = AgentDetails::where('name', $instanceName)->first();
            if ($row) {
                return $row;
            }
        }
        if ($instanceId !== null && $instanceId !== '') {
            $row = AgentDetails::where('instance_id', $instanceId)->first();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Send a WhatsApp text reply through an instance. Never throws.
     *
     * Heywave/Chatterly: POST /api/instance/send/{name}?token={INSTANCE_TOKEN}
     * with body { number, message }. The INSTANCE token (agentDetails.token) is required —
     * the admin token is rejected here.
     */
    public function sendText(?AgentDetails $agent, string $to, string $message): bool
    {
        if ($to === '' || $message === '') {
            return false;
        }

        $instance = $agent?->name;
        $token    = $agent?->token; // per-instance token, NOT the admin token

        if (! $instance || ! $token) {
            Log::warning('[chatterly] missing instance name/token; cannot send', ['to' => $to]);

            return false;
        }

        try {
            $resp = Http::withoutVerifying()
                ->connectTimeout(5)
                ->timeout(8)
                ->asJson()
                ->post($this->baseUrl.'/api/instance/send/'.urlencode($instance).'?token='.urlencode($token), [
                    'number'  => $to,
                    'message' => $message,
                ]);

            Log::info('[chatterly] send', [
                'to'       => $to,
                'instance' => $instance,
                'status'   => $resp->status(),
                'body'     => mb_substr($resp->body(), 0, 200),
            ]);

            return $resp->successful();
        } catch (\Throwable $e) {
            Log::error('[chatterly] send failed: '.$e->getMessage(), ['to' => $to]);

            return false;
        }
    }
}

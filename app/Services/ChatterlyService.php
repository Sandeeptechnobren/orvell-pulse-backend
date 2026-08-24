<?php

namespace App\Services;

use App\Models\AgentDetails;

/**
 * Thin adapter over the Chatterly / Heywave WhatsApp gateway.
 *
 *  - resolveAgent(): given the instance a message arrived on (name or id), find the matching
 *    `agentDetails` row created during QR provisioning — this tells us whether the number is
 *    the ADMIN agent or the CUSTOMER agent.
 *  - sendText(): kept as a convenience wrapper; the sending itself lives in WhatsAppSender.
 */
class ChatterlyService
{
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
     * Delegates to WhatsAppSender so there is a single implementation and a single view of
     * what "delivered" means. This previously returned $resp->successful(), which reported
     * false for every message the gateway delivered — it answers 500 with a serialisation
     * error while still sending. New code should call WhatsAppSender directly and read the
     * full result; this wrapper exists for the callers that already depend on the bool.
     */
    public function sendText(?AgentDetails $agent, string $to, string $message): bool
    {
        return app(WhatsAppSender::class)
            ->viaAgent($agent, $to, $message)['delivered'];
    }
}

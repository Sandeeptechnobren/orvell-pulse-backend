<?php

namespace App\Services;

use App\Http\Request\WhatsAppWebhookRequest;
use Illuminate\Support\Facades\Cache;

class WhatsAppWebhookService
{
    public function __construct(
        private ChatterlyService $chatterly,
        private WhatsAppAgentService $bot,
        private WhatsAppMessageRecorder $ledger,
        private WhatsAppCustomerRegistry $customers,
        private WhatsAppSender $sender,
    ) {
    }

    public function handle(WhatsAppWebhookRequest $request, ?string $channel = null): array
    {
        if ($request->isEcho()) {
            return ['ok' => true, 'skipped' => 'echo'];
        }

        $agent  = $this->chatterly->resolveAgent($request->instanceName(), $request->instanceId());
        $sender = $request->sender();
        $role   = $this->resolveRole($channel, $agent?->agent_type);

        if (! $sender || $request->isProtocolEvent()) {
            return ['ok' => true, 'skipped' => 'not-a-message'];
        }

        $this->store($request, $sender, $agent);

        if ($role !== 'admin') {
            $this->customers->rememberSender($sender, $agent);
        }

        if (! $request->isText()) {
            return ['ok' => true, 'stored' => true, 'skipped' => 'unsupported-type:'.$request->type()];
        }

        if (! $request->hasMessage()) {
            return ['ok' => true, 'stored' => true, 'skipped' => 'empty'];
        }

        if (! Cache::add($request->fingerprint(), 1, 600)) {
            return ['ok' => true, 'skipped' => 'duplicate'];
        }

        $this->markConnected($agent);
        $this->reply($role, $sender, (string) $request->body(), $agent);

        return ['ok' => true, 'stored' => true, 'role' => $role, 'queued' => true];
    }

    /**
     * Handle a message given directly rather than by a gateway, returning the reply
     * instead of sending it.
     */
    public function handleDirect(string $role, string $sender, string $body): array
    {
        $role = in_array($role, ['admin', 'customer'], true) ? $role : 'customer';

        $this->ledger->inbound($sender, 'system', $body);

        if ($role !== 'admin') {
            $this->customers->rememberSender($sender);
        }

        $result = $this->bot->handle($role, $sender, $body);

        $this->ledger->outbound($sender, $result['reply'], true, ['status' => 'returned']);

        return [
            'ok'     => true,
            'action' => $result['action'],
            'reply'  => $result['reply'],
        ];
    }

    /**
     * The reply is sent after the response so the gateway is not kept waiting and does
     * not retry the delivery.
     */
    private function reply(string $role, string $sender, string $body, $agent): void
    {
        $bot        = $this->bot;
        $whatsapp   = $this->sender;

        dispatch(function () use ($bot, $whatsapp, $agent, $role, $sender, $body) {
            $result = $bot->handle($role, $sender, $body);

            $whatsapp->viaAgent($agent, $sender, $result['reply']);
        })->afterResponse();
    }

    private function store(WhatsAppWebhookRequest $request, string $sender, $agent): void
    {
        $this->ledger->inbound($sender, $request->recipient(), $request->body(), [
            'type'          => $request->type(),
            'media_url'     => $request->mediaUrl(),
            'instance'      => $agent,
            'instance_name' => $request->instanceName(),
            'timestamp'     => $request->timestamp(),
        ]);
    }

    /**
     * The endpoint that was called wins, because a newly provisioned instance has no
     * agent_type stored and would otherwise fall through to the buyer flow.
     */
    private function resolveRole(?string $channel, ?string $storedType): string
    {
        $role = $channel ?: $storedType;

        return in_array($role, ['admin', 'customer'], true) ? $role : 'customer';
    }

    private function markConnected($agent): void
    {
        if ($agent && $agent->status !== 'connected') {
            $agent->update(['status' => 'connected']);
        }
    }
}

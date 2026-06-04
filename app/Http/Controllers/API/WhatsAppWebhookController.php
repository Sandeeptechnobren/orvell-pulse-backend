<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ChatterlyService;
use App\Services\WhatsAppAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound WhatsApp webhooks.
 *
 *  POST /api/whatsapp/webhook    — simple/test shape { agent_type, from, message }; reply returned.
 *  POST /api/whatsapp/chatterly  — real Chatterly/Heywave gateway payload. The agent
 *                                  (admin|customer) is resolved from the instance the message
 *                                  arrived on (the QR-scanned number), then the reply is sent
 *                                  back to the sender through that same instance.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private WhatsAppAgentService $bot)
    {
    }

    /** Simple/test webhook: explicit agent_type. Reply returned in the HTTP response. */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_type' => 'required|in:admin,customer',
            'from'       => 'required|string',
            'message'    => 'required|string',
        ]);

        $result = $this->bot->handle($data['agent_type'], $data['from'], $data['message']);

        return response()->json([
            'ok'     => true,
            'action' => $result['action'],
            'reply'  => $result['reply'],
        ]);
    }

    /** Real Chatterly gateway webhook: resolve agent from instance, process, send reply back. */
    public function chatterly(Request $request, ChatterlyService $chatterly): JsonResponse
    {
        $p = $request->all();

        // Diagnostic: log every webhook hit (even unparseable ones) so we can see exactly what Chatterly sends.
        \Illuminate\Support\Facades\Log::info('[wa-webhook] hit', ['keys' => array_keys($p), 'payload' => mb_substr(json_encode($p), 0, 600)]);

        // Ignore our own outbound messages / echoes (prevents reply loops).
        if (data_get($p, 'data.fromMe') === true || data_get($p, 'fromMe') === true) {
            return response()->json(['ok' => true, 'skipped' => 'outbound']);
        }

        // Real Chatterly shape: top-level `instance`, message under `data` (data.from / data.body).
        $instanceName = $p['instance'] ?? $p['instanceName'] ?? $p['instance_name'] ?? data_get($p, 'instance.name');
        $instanceId   = $p['instance_id'] ?? $p['instanceId'] ?? data_get($p, 'instance.id');
        $fromRaw      = data_get($p, 'data.from') ?? $p['from'] ?? $p['sender'] ?? $p['number'] ?? $p['phone'] ?? $p['chatId'] ?? data_get($p, 'message.from');
        $message      = data_get($p, 'data.body') ?? data_get($p, 'data.message') ?? data_get($p, 'data.text') ?? $p['message'] ?? $p['text'] ?? $p['body'] ?? data_get($p, 'message.text') ?? data_get($p, 'message.body');
        $type         = data_get($p, 'data.type', 'chat');

        if (is_array($message)) {
            $message = $message['text'] ?? $message['body'] ?? null;
        }

        // Only handle text chats — ignore documents, images, status updates, etc.
        if ($type && ! in_array($type, ['chat', 'text'], true)) {
            return response()->json(['ok' => true, 'skipped' => 'non-text:'.$type]);
        }

        if (! $fromRaw || $message === null || $message === '') {
            return response()->json(['ok' => true, 'skipped' => 'unparseable', 'received_keys' => array_keys($p)]);
        }

        // De-duplicate: Chatterly retries the same message several times — reply only once.
        $dedupeKey = 'wa:'.md5($instanceName.'|'.$fromRaw.'|'.data_get($p, 'data.timestamp', '').'|'.$message);
        if (\Illuminate\Support\Facades\Cache::get($dedupeKey)) {
            return response()->json(['ok' => true, 'skipped' => 'duplicate']);
        }
        \Illuminate\Support\Facades\Cache::put($dedupeKey, 1, 600);

        // Keep the full chat id (e.g. 1669…@lid) for replying; digits = customer identity.
        $fromRaw = (string) $fromRaw;
        $fromId  = preg_replace('/[^0-9]/', '', $fromRaw) ?: $fromRaw;

        $agent     = $chatterly->resolveAgent($instanceName, $instanceId);
        // A message arrived → the instance is connected. Keep the stored status in sync.
        if ($agent && $agent->status !== 'connected') {
            $agent->update(['status' => 'connected']);
        }
        $agentType = $agent->agent_type ?? ($p['agent_type'] ?? 'customer');
        $agentType = in_array($agentType, ['admin', 'customer'], true) ? $agentType : 'customer';

        // Ack the webhook fast, then process + reply AFTER the response so Chatterly doesn't time out and retry.
        // Use the full chat id (e.g. 1669…@lid) as the customer identity so confirmations can reach them later.
        $bot = $this->bot;
        dispatch(function () use ($bot, $chatterly, $agent, $agentType, $fromRaw, $message) {
            $result = $bot->handle($agentType, $fromRaw, (string) $message);
            $chatterly->sendText($agent, $fromRaw, $result['reply']);
        })->afterResponse();

        return response()->json(['ok' => true, 'agent_type' => $agentType, 'queued' => true]);
    }
}

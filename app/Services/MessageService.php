<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\AgentDetails;
use App\Models\agentPrompt;
use App\Models\space_iq;
use App\Models\Space;

class MessageService
{
// public function initialiseAgent($agentType)
// {
//     $user = Auth::user();

//     if (!$user) {
//         return response()->json([
//             'error' => 'Unauthenticated'
//         ], 401);
//     }

//     $instanceName = Str::slug($user->name . '-' . $agentType, '-');

//     $agentDetails = AgentDetails::where('client_id', $user->id)
//         ->where('agent_type', $agentType)
//         ->first();

//     if (!$agentDetails) {

//         $createResponse = Http::post(
//             'https://chatterly.easycoders.in/api/instance/create',
//             [
//                 'token' => env('CHATTERLY_ADMIN_TOKEN'),
//                 'instance_name' => $instanceName,
//             ]
//         );

//         if (!$createResponse->successful()) {
//             return response()->json([
//                 'error' => 'Failed to create instance',
//                 'response' => $createResponse->json(),
//             ], 500);
//         }

//         $instance = $createResponse->json('instance');

//         $agentDetails = AgentDetails::updateOrCreate(
//             [
//                 'client_id'  => $user->id,
//                 'agent_type' => $agentType,
//             ],
//             [
//                 'uuid'        => (string) Str::uuid(),
//                 'instance_id' => $instance['id'] ?? null,
//                 'token'       => $instance['token'] ?? null,
//                 'name'        => $instance['name'] ?? $instanceName,
//                 'status'      => $instance['status'] ?? 'pending',
//                 'creationTS'  => now()->timestamp,
//                 'stopped'     => false,
//                 '_isPremium'  => false,
//             ]
//         );
//     }

//     $qrUrl = "https://chatterly.easycoders.in/api/instance/qrpng/" .
//         $agentDetails->instance_id .
//         "?token=" .
//         env('CHATTERLY_ADMIN_TOKEN');

//     $qrResponse = Http::withoutVerifying()->get($qrUrl);

//     if (!$qrResponse->successful()) {
//         return response()->json([
//             'error' => 'QR fetch failed',
//             'instance_id' => $agentDetails->instance_id,
//             'qr_status' => $qrResponse->status(),
//             'qr_response' => $qrResponse->body(),
//         ], 500);
//     }

//     $contentType = $qrResponse->header('content-type');

//     if ($contentType && str_contains($contentType, 'image')) {

//         return response()->json([
//             'success' => true,
//             'instance_id' => $agentDetails->instance_id,
//             'instance_name' => $agentDetails->name,
//             'qr' => 'data:image/png;base64,' . base64_encode($qrResponse->body())
//         ]);
//     }

//     return response()->json([
//         'error' => 'QR endpoint did not return an image',
//         'content_type' => $contentType,
//         'response' => $qrResponse->body(),
//     ], 500);
// }
    public function initialiseAgent($agentType)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated'
            ], 401);
        }
        $instanceName = Str::slug($user->name . '-' . $agentType, '-');
        $base = rtrim(config('chatterly.base_url'), '/');

        $agentDetails = AgentDetails::where('client_id', $user->id)
            ->where('agent_type', $agentType)
            ->first();
        if (!$agentDetails) {
    

            $createResponse = Http::post(
                "{$base}/api/instance/create",
                [
                    'token' => config('chatterly.admin_token'),
                    'instance_name' => $instanceName,
                ]
            );
            // $createResponse = Http::post(
            //     'https://chatterly.easycoders.in/api/instance/create',
            //     [
            //         'token' => config('chatterly.admin_token'),
            //         'instance_name' => $instanceName,
            //     ]
            // );
            if (!$createResponse->successful()) {
                return response()->json([
                    'error' => 'Failed to create instance',
                    'response' => $createResponse->json(),
                ], 500);
            }

            $instance = $createResponse->json('instance');

            $agentDetails = AgentDetails::updateOrCreate(
                [
                    'client_id'  => $user->id, 
                    'agent_type' => $agentType,
                ],
                [
                    'uuid'        => (string) Str::uuid(),
                    'instance_id' => $instance['id'] ?? null,
                    'token'       => $instance['token'] ?? null,
                    'name'        => $instance['name'] ?? $instanceName,
                    'status'      => $instance['status'] ?? 'pending',
                    'creationTS'  => now()->timestamp,
                    'stopped'     => false,
                    '_isPremium'  => false,
                ]
            );
        }

        $token = config('chatterly.admin_token');
        // $base  = rtrim(env('CHATTERLY_BASE_URL', 'https://chatterly.easycoders.in'), '/');
        // $base = rtrim(config('chatterly.base_url'), '/');
        // Start/connect the instance — without this the QR endpoint returns "Instance not running".
        $connect = Http::withoutVerifying()->connectTimeout(5)->timeout(10)
            ->post("{$base}/api/instance/connect/" . urlencode($agentDetails->name) . "?token={$token}");
        $status = strtolower((string) $connect->json('status'));
        // Auto-configure the inbound webhook so Chatterly forwards messages to our bot.
        // Set CHATTERLY_INBOUND_URL in .env to your public base URL (e.g. https://xxxx.ngrok.io).
        // $inbound = env('CHATTERLY_INBOUND_URL');
        // $inbound = env('CHATTERLY_INBOUND_URL');
        $inbound = config('chatterly.inbound_url');
        // dd($inbound);
        if ($inbound) {
            // Http::withoutVerifying()->timeout(15)->post(
            //     "{$base}/api/instance/webhook/" . urlencode($agentDetails->name) . "?token={$token}",
            //     ['webhookUrl' => rtrim($inbound, '/') . '/api/whatsapp/chatterly']
            // );
             Http::withoutVerifying()
                ->timeout(config('chatterly.timeout'))
                ->post(
                    "{$base}/api/instance/webhook/"
                    . urlencode($agentDetails->name)
                    . "?token={$token}",
                    [
                        'webhookUrl' => rtrim($inbound, '/')
                            . '/api/whatsapp/chatterly'
                    ]
                );
        }

        // Already linked → no QR needed.
        if (in_array($status, ['connected', 'authenticated', 'ready', 'online'], true)) {
            $agentDetails->update(['status' => 'connected']);

            $webhookUrl = url('/api/whatsapp/chatterly');

    Http::withoutVerifying()
        ->timeout(config('chatterly.timeout'))
        ->post(
            "{$base}/api/instance/webhook/"
            . urlencode($agentDetails->name)
            . "?token={$token}",
            [
                'webhookUrl' => $webhookUrl,
            ]
        );

            return response()->json([
                'success'       => true,
                'connected'     => true,
                'agent_type'    => $agentType,
                'instance_name' => $agentDetails->name,
                'webhook_url'   => $webhookUrl,
                'message'       => "This number is already connected for the {$agentType} agent.",
            ]);
        }

        // Fetch the QR as a PNG (retry briefly while the instance boots).
        $qrUrl = "{$base}/api/instance/qrpng/" . urlencode($agentDetails->name) . "?token={$token}";
        $png   = null;

        for ($i = 0; $i < 3; $i++) {
            $qrResponse = Http::withoutVerifying()->connectTimeout(5)->timeout(8)->get($qrUrl);
            $ctype      = (string) $qrResponse->header('Content-Type');

            if ($qrResponse->successful() && str_contains($ctype, 'image')) {
                $png = $qrResponse->body();
                break;
            }
            usleep(1000000); // 1s before retrying
        }

        if (! $png) {
            return response()->json([
                'error'         => 'QR not ready yet — the instance is still starting. Tap "Reload QR" in a moment.',
                'instance_name' => $agentDetails->name,
                'status'        => $status,
            ], 200);
        }

        // Return a data-URI the frontend can drop straight into <img src>.
        return response()->json([
            'success'       => true,
            'agent_type'    => $agentType,
            'instance_id'   => $agentDetails->instance_id,
            'instance_name' => $agentDetails->name,
            'qr_url'        => 'data:image/png;base64,' . base64_encode($png),
        ]);
    }

}
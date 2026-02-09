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


class WhatsappMessageService
{

private function getQrCode($instanceId, $token)
{
    if ($this->isQrScanned($token)) {
        return response()->json([
            'linked' => true,
            'message' => 'WhatsApp already linked',
            'whatsapp_linking_status_' => 1
        ]);
    }
    $client = new \GuzzleHttp\Client();
    $qrResponse = $client->request(
        'GET',
        'https://gate.whapi.cloud/users/login/image?wakeup=true',
        [
            'headers' => [
                'accept'        => 'image/png',
                'authorization' => 'Bearer ' . $token,
            ],
        ]
    );
    if ($qrResponse->getStatusCode() === 200) {
        return response(
            $qrResponse->getBody(),
            200,
            ['Content-Type' => 'image/png']
        );
    }
    return response()->json([
        'error' => 'Failed to fetch QR'
    ], 500);
}

public function initialiseAgent()
{
    $user = Auth::user();
    if (! $user) {
        return response()->json(['error' => 'Unauthenticated'], 401);
    }
    $agentDetails = AgentDetails::where('user_id', $user->id)->first();
    if ($agentDetails) {
        return $this->getQrCode(
            $agentDetails->instance_id,
            $agentDetails->token
        );
    }
    $response = Http::withToken(env('WHAPI_MASTER_TOKEN'))
        ->put('https://manager.whapi.cloud/channels', [
            'name'      => $user->name,
            'projectId' => 'TSu2uo9AC9nu91aoz6Ae',
        ]);
    if (! $response->successful()) {
        return response()->json([
            'error'  => 'Failed to create WhatsApp instance',
            'status' => $response->status(),
            'body'   => $response->body(),
        ], $response->status());
    }
    $data = $response->json();
    $agentDetails = AgentDetails::create([
        'uuid'        => (string) Str::uuid(),
        'user_id'     => $user->id,
        'instance_id' => $data['id'] ?? null,
        'name'        => $data['name'] ?? $user->name,
        'projectId'   => $data['projectId'] ?? 'TSu2uo9AC9nu91aoz6Ae',
        'ownerId'     => $data['ownerId'],
        'status'      => 'active',
        'server'      => $data['server'] ?? null,
        'token'       => $data['token'] ?? null,
        'creationTS'  => now()->timestamp,
        'stopped'     => false,
        '_isPremium'  => false,
    ]);
    return $this->getQrCode(
        $agentDetails->instance_id,
        $agentDetails->token
    );
}
private function isQrScanned(string $token): bool
{
    try {
        $client = new \GuzzleHttp\Client();

        $response = $client->request(
            'GET',
            'https://gate.whapi.cloud/health?wakeup=true&channel_type=web',
            [
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => $token,
                ],
            ]
        );

        $data = json_decode($response->getBody(), true);

        return !empty($data['user']);

    } catch (\Exception $e) {
        logger()->error('QR scan check failed', [
            'message' => $e->getMessage()
        ]);

        return false;
    }
}



    public function getByUuid($uuid)
    {
        return Order::where('uuid', $uuid)->first();
    }

    // public function storeAgentPrompt($prompt_description){
    //     $user=Auth::user();
    //     $prompt_description=agentPrompt::create([
    //         'user_id'=>$user->id,
    //         'prompt_description'=>$prompt_description
    //     ]);
    //     dd($prompt_description);
    // }
// public function storeOrUpdateAgentPrompt($userId, $promptDescription)
// {
//     // dd($userId,$promptDescription);
//     $space=Space::where('client_id',$userId)->firstorFail();
    
//     return space_iq::updateOrCreate(
//         ['space_id' => $space->id,
//         'prompt_content' => $promptDescription]
//     );
// }
public function storeOrUpdateAgentPrompt($userId, $promptDescription)
{
    $space = Space::where('client_id', $userId)->firstOrFail();

    return space_iq::updateOrCreate(
        ['space_id' => $space->id],
        ['prompt_content' => $promptDescription]
    );
}

}

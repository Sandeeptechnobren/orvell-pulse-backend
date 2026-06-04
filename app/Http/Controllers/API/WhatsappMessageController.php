<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Services\MessageService;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="WhatsApp Agent",
 *     description="WhatsApp agent initialization and prompt management APIs"
 * )
 */
class WhatsappMessageController extends Controller
{
    protected MessageService $service;

    public function __construct(MessageService $service)
    {
        $this->service = $service;
    }

public function initialiseAgent(Request $request)
{
    $validated = $request->validate([
        'agent_type' => 'required|in:admin,customer'
    ]);

    return $this->service->initialiseAgent(
        $validated['agent_type']
    );
}
}
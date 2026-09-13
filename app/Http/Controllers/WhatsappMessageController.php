<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Services\WhatsappMessageService;
use Illuminate\Http\Request;

class WhatsappMessageController extends Controller
{
    protected WhatsappMessageService $service;

    public function __construct(WhatsappMessageService $service)
    {
        $this->service = $service;
    }

    public function initialiseAgent(Request $request)
    {
        Auth::user(); // ensure auth context

        return $this->service->initialiseAgent();
    }

    public function getQRcode(Request $request)
    {
        Auth::user(); // ensure auth context

        return $this->service->getQrCode();
    }

    public function storeAgentPrompt(Request $request)
    {
        $validated = $request->validate([
            'prompt_description' => 'required|string'
        ]);

        $agentPrompt = $this->service->storeOrUpdateAgentPrompt(
            auth()->id(),
            $validated['prompt_description']
        );

        return response()->json([
            'success' => true,
            'data'    => $agentPrompt
        ]);
    }
}

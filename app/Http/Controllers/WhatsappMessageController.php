<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Services\WhatsappMessageService;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="WhatsApp Agent",
 *     description="WhatsApp agent initialization and prompt management APIs"
 * )
 */
class WhatsappMessageController extends Controller
{
    protected WhatsappMessageService $service;

    public function __construct(WhatsappMessageService $service)
    {
        $this->service = $service;
    }

    /**
     * Initialise WhatsApp Agent
     *
     * @OA\Post(
     *     path="/api/whatsapp/agent/initialise",
     *     tags={"WhatsApp Agent"},
     *     summary="Initialise WhatsApp agent",
     *     description="Initialises WhatsApp agent for the authenticated user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Agent initialised successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Agent initialised successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     )
     * )
     */
    public function initialiseAgent(Request $request)
    {
        Auth::user(); // ensure auth context

        return $this->service->initialiseAgent();
    }

    /**
     * Get WhatsApp QR Code
     *
     * @OA\Get(
     *     path="/api/whatsapp/agent/qrcode",
     *     tags={"WhatsApp Agent"},
     *     summary="Get WhatsApp QR Code",
     *     description="Returns QR code for WhatsApp agent login",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="QR code generated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="qr_code", type="string", example="base64_qr_code_string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     )
     * )
     */
    public function getQRcode(Request $request)
    {
        Auth::user(); // ensure auth context

        return $this->service->getQrCode();
    }

    /**
     * Store or Update Agent Prompt
     *
     * @OA\Post(
     *     path="/api/whatsapp/agent/prompt",
     *     tags={"WhatsApp Agent"},
     *     summary="Store or update agent prompt",
     *     description="Stores or updates WhatsApp agent prompt for authenticated user",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"prompt_description"},
     *             @OA\Property(
     *                 property="prompt_description",
     *                 type="string",
     *                 example="You are a helpful WhatsApp sales assistant."
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Prompt saved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 ref="#/components/schemas/AgentPrompt"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     )
     * )
     */
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

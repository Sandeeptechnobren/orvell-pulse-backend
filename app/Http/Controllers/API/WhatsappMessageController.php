<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Services\MessageService;
use App\Services\AgentPromptService;
use Illuminate\Http\JsonResponse;
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
    protected AgentPromptService $promptService;

    public function __construct(MessageService $service, AgentPromptService $promptService)
    {
        $this->service = $service;
        $this->promptService = $promptService;
    }

    // public function initialiseAgent(Request $request)
    // {
    //     $validated = $request->validate([
    //         'agent_type' => 'required|in:admin,customer'
    //     ]);

    //     return $this->service->initialiseAgent(
    //         $validated['agent_type']
    //     );
    // }
    /**
     * Initialise WhatsApp Agent
     *
     * @OA\Post(
     *     path="/api/agent/initialiseAgent",
     *     tags={"WhatsApp Agent"},
     *     summary="Initialise WhatsApp agent instance",
     *     description="Creates or connects a Chatterly WhatsApp instance for the customer or admin agent",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"agent_type"},
     *             @OA\Property(property="agent_type", type="string", enum={"admin", "customer"}, example="customer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Agent instance initialised successfully or QR returned",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="agent_type", type="string", example="customer"),
     *             @OA\Property(property="instance_name", type="string", example="admin-customer"),
     *             @OA\Property(property="qr_url", type="string", example="data:image/png;base64,...")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function initialiseAgent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_type' => 'required|in:admin,customer'
        ]);

        return $this->service->initialiseAgent(
            $validated['agent_type']
        );
    }

    /**
     * Store or Update Agent IQ Prompt
     *
     * @OA\Post(
     *     path="/api/agent/storeAgentPrompt",
     *     tags={"WhatsApp Agent"},
     *     summary="Store or update Customer IQ or Admin IQ Prompt",
     *     description="Saves custom interaction instructions and tone for either customer or admin WhatsApp agent",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"prompt_for", "prompt_description"},
     *             @OA\Property(property="prompt_for", type="string", enum={"admin", "customer"}, example="customer"),
     *             @OA\Property(
     *                 property="prompt_description",
     *                 type="string",
     *                 example="You are the Orvell customer WhatsApp assistant. Be polite, concise, and helpful."
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Prompt saved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Customer agent prompt saved successfully."),
     *             @OA\Property(property="data", ref="#/components/schemas/AgentPrompt")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function storeAgentPrompt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt_for'         => 'required|in:admin,customer',
            'prompt_description' => 'required|string|max:10000',
        ]);

        $user = Auth::user();
        $clientId = $user?->client_id ?? $user?->id ?? 1;

        $prompt = $this->promptService->storeOrUpdatePrompt(
            clientId: (int) $clientId,
            promptFor: $validated['prompt_for'],
            promptDescription: $validated['prompt_description'],
            userId: $user?->id
        );

        $label = ucfirst($validated['prompt_for']);

        return response()->json([
            'success' => true,
            'message' => "{$label} agent prompt saved successfully.",
            'data'    => $prompt,
        ]);
    }

    /**
     * Get Stored Agent IQ Prompt
     *
     * @OA\Get(
     *     path="/api/agent/agentPrompt",
     *     tags={"WhatsApp Agent"},
     *     summary="Retrieve Customer IQ or Admin IQ Prompt",
     *     description="Fetches the current stored custom prompt for the customer or admin agent, or default prompt",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="prompt_for",
     *         in="query",
     *         required=true,
     *         description="Agent role ('customer' or 'admin')",
     *         @OA\Schema(type="string", enum={"admin", "customer"}, example="customer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Prompt retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="prompt_for", type="string", example="customer"),
     *                 @OA\Property(property="prompt_description", type="string", example="You are the Orvell assistant..."),
     *                 @OA\Property(property="is_custom", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function getAgentPrompt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt_for' => 'required|in:admin,customer',
        ]);

        $user = Auth::user();
        $clientId = $user?->client_id ?? $user?->id ?? 1;

        $prompt = $this->promptService->getPrompt((int) $clientId, $validated['prompt_for']);
        $description = $prompt ? $prompt->prompt_description : $this->promptService->getDefaultPrompt($validated['prompt_for']);

        return response()->json([
            'success' => true,
            'data'    => [
                'prompt_for'         => $validated['prompt_for'],
                'prompt_description' => $description,
                'is_custom'          => ($prompt !== null && !empty($prompt->prompt_description)),
                'prompt'             => $prompt,
            ],
        ]);
    }

}
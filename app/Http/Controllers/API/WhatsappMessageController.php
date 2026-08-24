<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Request\AgentInstanceRequest;
use App\Http\Request\AgentPromptRequest;
use App\Http\Resources\AgentPromptResource;
use App\Services\AgentService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;

class WhatsappMessageController extends Controller
{
    use ResponseTrait;

    public function __construct(private AgentService $service)
    {
    }

    /**
     * Returns the QR image when the instance still needs linking, otherwise JSON.
     */
    public function initialiseAgent(AgentInstanceRequest $request)
    {
        return $this->service->initialise($request->agentType());
    }

    public function storeAgentPrompt(AgentPromptRequest $request): JsonResponse
    {
        $prompt = $this->service->savePrompt(
            $request->promptFor(),
            $request->promptDescription(),
        );

        return $this->success(
            ucfirst($request->promptFor()).' agent prompt saved successfully.',
            new AgentPromptResource($prompt),
        );
    }

    public function getAgentPrompt(AgentPromptRequest $request): JsonResponse
    {
        return $this->success(
            'Agent prompt fetched successfully.',
            $this->service->prompt($request->promptFor()),
        );
    }
}

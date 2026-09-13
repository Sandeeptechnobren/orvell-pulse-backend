<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Request\WhatsAppWebhookRequest;
use App\Services\WhatsAppWebhookService;
use Illuminate\Http\JsonResponse;

class WhatsAppWebhookController extends Controller
{
    public function __construct(private WhatsAppWebhookService $service)
    {
    }

    public function customer(WhatsAppWebhookRequest $request): JsonResponse
    {
        return response()->json($this->service->handle($request, 'customer'));
    }

    public function admin(WhatsAppWebhookRequest $request): JsonResponse
    {
        return response()->json($this->service->handle($request, 'admin'));
    }

    public function handle(WhatsAppWebhookRequest $request): JsonResponse
    {
        $result = $this->service->handleDirect(
            (string) $request->input('agent_type', 'customer'),
            (string) $request->input('from'),
            (string) $request->input('message'),
        );

        return response()->json($result);
    }

    public function chatterly(WhatsAppWebhookRequest $request): JsonResponse
    {
        return response()->json($this->service->handle($request));
    }
}

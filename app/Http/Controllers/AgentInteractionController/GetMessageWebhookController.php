<?php 
namespace App\Http\Controllers\AgentInteractionController;
use App\Services\AgentInteractionService\GetMessageWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
class GetMessageWebhookController extends Controller{
      public function __construct(
        protected GetMessageWebhookService $webhookService
    ) {}
    public function receive(Request $request)
    {
        $result = $this->webhookService->receive($request);
        return response()->json([
            'status' => true,
            'message' => 'Webhook received successfully',
            'data' => $result,
        ]);
    }
}
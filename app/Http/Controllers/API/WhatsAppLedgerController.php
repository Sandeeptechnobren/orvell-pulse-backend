<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappMessageResource;
use App\Services\WhatsAppLedgerService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppLedgerController extends Controller
{
    use ResponseTrait;

    public function __construct(private WhatsAppLedgerService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $messages = $this->service->list($request->only(['direction', 'status', 'wa_id', 'per_page']));

        return $this->paginated('Messages fetched successfully', $messages);
    }

    public function conversation(string $waId): JsonResponse
    {
        $messages = $this->service->conversation($waId);

        return $this->paginated('Conversation fetched successfully', $messages);
    }

    private function paginated(string $message, $paginator): JsonResponse
    {
        return response()->json([
            'status'  => true,
            'message' => $message,
            'data'    => WhatsappMessageResource::collection($paginator),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }
}

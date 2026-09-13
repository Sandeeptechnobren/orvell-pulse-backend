<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConvertOrderRequestRequest;
use App\Http\Requests\DeclineOrderRequestRequest;
use App\Http\Resources\OrderRequestResource;
use App\Services\OrderRequestService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderRequestController extends Controller
{
    public function __construct(
        private readonly OrderRequestService $service
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $requests = $this->service->list(
                $request->only(['status', 'customer_id', 'search', 'per_page'])
            );

            return response()->json([
                'success' => true,
                'data' => OrderRequestResource::collection($requests->items()),
                'meta' => [
                    'current_page' => $requests->currentPage(),
                    'last_page' => $requests->lastPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Order request list failed', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to fetch order requests.'], 500);
        }
    }

    public function show(string $uuid): JsonResponse
    {
        try {
            $request = $this->service->getByUuid($uuid);

            return response()->json([
                'success' => true,
                'data' => new OrderRequestResource($request),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Order request not found.'], 404);
        } catch (Throwable $e) {
            Log::error('Order request fetch failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to fetch order request.'], 500);
        }
    }

    public function convert(ConvertOrderRequestRequest $request, string $uuid): JsonResponse
    {
        try {
            $converted = $this->service->convert(
                $uuid,
                $request->validated('lines'),
                auth()->id(),
                $request->validated('notes')
            );

            return response()->json([
                'success' => true,
                'message' => "Request {$converted->request_no} converted. Invoice {$converted->order?->invoice_code} generated and the customer has been notified.",
                'data' => new OrderRequestResource($converted),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Order request not found.'], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Order request convert failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to convert the order request.'], 500);
        }
    }

    public function decline(DeclineOrderRequestRequest $request, string $uuid): JsonResponse
    {
        try {
            $declined = $this->service->decline(
                $uuid,
                $request->validated('reason'),
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => "Request {$declined->request_no} declined and the customer has been notified.",
                'data' => new OrderRequestResource($declined),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Order request not found.'], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Order request decline failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to decline the order request.'], 500);
        }
    }
}

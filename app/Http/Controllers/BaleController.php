<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBaleRequest;
use App\Http\Requests\UpdateBaleRequest;
use App\Http\Resources\BaleBatchResource;
use App\Services\BaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class BaleController extends Controller
{
    public function __construct(
        private readonly BaleService $baleService
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $batches = $this->baleService->list(
            $request->only(['container_id', 'category_id', 'status', 'per_page'])
        );

        return BaleBatchResource::collection($batches);
    }

    public function store(StoreBaleRequest $request): JsonResponse
    {
        try {
            $batches = $this->baleService->bulkCreate(
                $request->validated('container_id'),
                $request->validated('lines')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $total = collect($request->validated('lines'))->sum('quantity');

        return BaleBatchResource::collection($batches)
            ->additional(['message' => $total . ' bales registered successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $bale): BaleBatchResource
    {
        return new BaleBatchResource($this->baleService->find($bale));
    }

    public function update(UpdateBaleRequest $request, int $bale): JsonResponse
    {
        try {
            $updated = $this->baleService->adjust($bale, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (new BaleBatchResource($updated))
            ->additional(['message' => 'Stock adjusted successfully.'])
            ->response();
    }

    public function destroy(int $bale): JsonResponse
    {
        try {
            $this->baleService->delete($bale);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Batch deleted successfully.']);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->baleService->stockSummary()]);
    }

    public function notify(int $bale, \App\Services\StockNotificationService $notifications): JsonResponse
    {
        try {
            $batch = $this->baleService->find($bale);

            if ($batch->qty_available <= 0) {
                return response()->json([
                    'message' => 'This batch has no available stock to announce.',
                ], 409);
            }

            $count = $notifications->broadcastAvailability($batch);

            return response()->json([
                'message' => "Stock update queued for {$count} customer" . ($count === 1 ? '' : 's') . '.',
                'recipients' => $count,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Batch not found.'], 404);
        }
    }
}

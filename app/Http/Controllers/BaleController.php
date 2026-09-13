<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBaleRequest;
use App\Http\Requests\UpdateBaleRequest;
use App\Http\Resources\BaleResource;
use App\Models\Bale;
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
        $bales = $this->baleService->list(
            $request->only(['container_id', 'category_id', 'status', 'search', 'per_page'])
        );

        return BaleResource::collection($bales);
    }

    public function store(StoreBaleRequest $request): JsonResponse
    {
        try {
            $bales = $this->baleService->bulkCreate(
                $request->validated('container_id'),
                $request->validated('lines')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return BaleResource::collection($bales)
            ->additional(['message' => $bales->count() . ' bales registered successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Bale $bale): BaleResource
    {
        return new BaleResource($this->baleService->find($bale));
    }

    public function update(UpdateBaleRequest $request, Bale $bale): JsonResponse
    {
        try {
            $updated = $this->baleService->update($bale, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (new BaleResource($updated))
            ->additional(['message' => 'Bale updated successfully.'])
            ->response();
    }

    public function destroy(Bale $bale): JsonResponse
    {
        try {
            $this->baleService->delete($bale);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Bale deleted successfully.']);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->baleService->stockSummary()]);
    }
}
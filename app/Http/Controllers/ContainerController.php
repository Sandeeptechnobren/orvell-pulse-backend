<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContainerRequest;
use App\Http\Requests\UpdateContainerRequest;
use App\Http\Resources\ContainerResource;
use App\Models\Container;
use App\Services\ContainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class ContainerController extends Controller
{
    public function __construct(
        private readonly ContainerService $containerService
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $containers = $this->containerService->list(
            $request->only(['status', 'supplier_id', 'search', 'arrival_from', 'arrival_to', 'per_page'])
        );

        return ContainerResource::collection($containers);
    }

    public function store(StoreContainerRequest $request): JsonResponse
    {
        $container = $this->containerService->create(
            $request->validated(),
            $request->user()->id
        );

        return (new ContainerResource($container))
            ->additional(['message' => 'Container registered successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Container $container): ContainerResource
    {
        return new ContainerResource(
            $this->containerService->find($container)
        );
    }

    public function update(UpdateContainerRequest $request, Container $container): JsonResponse
    {
        $updated = $this->containerService->update($container, $request->validated());

        return (new ContainerResource($updated))
            ->additional(['message' => 'Container updated successfully.'])
            ->response();
    }

    public function destroy(Container $container): JsonResponse
    {
        try {
            $this->containerService->delete($container);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Container deleted successfully.']);
    }
}
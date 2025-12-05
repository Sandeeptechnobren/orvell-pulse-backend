<?php

namespace App\Http\Controllers;

use App\Http\Request\StockManagementRequest;
use App\Services\StockManagementService;
use App\Http\Resources\StockManagementResource;

class StockManagementController extends Controller
{
    protected $service;

    public function __construct(StockManagementService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return response()->json([
            'message' => 'Item categories fetched',
            'data' => StockManagementResource::collection($this->service->list())
        ]);
    }

    public function store(StockManagementRequest $request)
    {
        $item = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Item category created',
            'data' => new StockManagementResource($item)
        ]);
    }

    public function show($uuid)
    {
        $item = $this->service->getByUuid($uuid);

        return response()->json([
            'message' => 'Item category fetched',
            'data' => new StockManagementResource($item)
        ]);
    }

    public function update(StockManagementRequest $request, $uuid)
    {
        $item = $this->service->updateByUuid($uuid, $request->validated());

        return response()->json([
            'message' => 'Item category updated',
            'data' => new StockManagementResource($item)
        ]);
    }

    public function destroy($uuid)
    {
        $this->service->deleteByUuid($uuid);

        return response()->json([
            'message' => 'Item category deleted'
        ]);
    }
}

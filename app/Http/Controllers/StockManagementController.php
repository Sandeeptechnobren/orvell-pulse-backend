<?php

namespace App\Http\Controllers;

use App\Http\Request\StockManagementRequest;
use App\Services\StockManagementService;

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
            'data' => $this->service->list()
        ]);
    }

    public function store(StockManagementRequest $request)
    {
        // Service returns model instance, not ID
        $item = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Item category created',
            'data' => $item
        ]);
    }

    public function show($id)
    {
        $item = $this->service->getById($id);

        return response()->json([
            'message' => 'Item category fetched',
            'data' => $item
        ]);
    }

    public function update(StockManagementRequest $request, $id)
    {
        $item = $this->service->update($id, $request->validated());

        return response()->json([
            'message' => 'Item category updated',
            'data' => $item
        ]);
    }

    public function destroy($id)
    {
        $this->service->delete($id);

        return response()->json([
            'message' => 'Item category deleted'
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\StockManagementService;
use App\Http\Request\StockManagementRequest;
use App\Http\Resources\StockManagementResource;
use App\Traits\ResponseTrait;

class StockManagementController extends Controller
{
    use ResponseTrait;

    protected $service;

    public function __construct(StockManagementService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $stock = $this->service->list();

        return $this->success(
            'Stock list fetched successfully',
            $stock
        );
    }

    public function store(StockManagementRequest $request)
    {
        $stock = $this->service->create($request->validated());

        return $this->success(
            'Stock created successfully',
            new StockManagementResource($stock),
            201
        );
    }

    public function show($uuid)
    {
        $stock = $this->service->getByUuid($uuid);

        if (!$stock) {
            return $this->error('Stock not found', [], 404);
        }

        return $this->success(
            'Stock fetched successfully',
            new StockManagementResource($stock)
        );
    }

    public function update(StockManagementRequest $request, $uuid)
    {
        $stock = $this->service->update($uuid, $request->validated());

        if (!$stock) {
            return $this->error('Stock not found', [], 404);
        }

        return $this->success(
            'Stock updated successfully',
            new StockManagementResource($stock)
        );
    }

    public function destroy($uuid)
    {
        if (!$this->service->delete($uuid)) {
            return $this->error('Stock not found', [], 404);
        }

        return $this->success('Stock deleted successfully');
    }
}

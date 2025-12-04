<?php

namespace App\Http\Controllers;

use App\Http\Request\CustomerManagementRequest;
use App\Http\Resources\CustomerManagementResource;
use App\Services\CustomerManagementService;

class CustomerManagementController extends Controller
{
    protected $service;

    public function __construct(CustomerManagementService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return response()->json([
            'message' => 'Customers fetched',
            'data' => CustomerManagementResource::collection($this->service->list())
        ]);
    }

    public function store(CustomerManagementRequest $request)
    {
        $item = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Customer created',
            'data' => new CustomerManagementResource($item)
        ]);
    }

    public function show($uuid)
    {
        $item = $this->service->getByUuid($uuid);

        return response()->json([
            'message' => 'Customer fetched',
            'data' => new CustomerManagementResource($item)
        ]);
    }

    public function update(CustomerManagementRequest $request, $uuid)
    {
        $item = $this->service->update($uuid, $request->validated());

        return response()->json([
            'message' => 'Customer updated',
            'data' => new CustomerManagementResource($item)
        ]);
    }

    public function destroy($uuid)
    {
        $this->service->delete($uuid);

        return response()->json([
            'message' => 'Customer deleted'
        ]);
    }
}

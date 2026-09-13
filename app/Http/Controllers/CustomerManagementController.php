<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerManagementRequest;
use App\Http\Resources\CustomerManagementResource;
use App\Services\CustomerManagementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CustomerManagementController extends Controller
{
    protected $service;

    public function __construct(CustomerManagementService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $customers = $this->service->list(
                $request->only(['search', 'onboarding_status', 'city', 'per_page'])
            );

            return response()->json([
                'message' => 'Customers fetched',
                'data' => CustomerManagementResource::collection($customers->items()),
                'meta' => [
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'per_page' => $customers->perPage(),
                    'total' => $customers->total(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Customer list failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to fetch customers.'], 500);
        }
    }

    public function store(CustomerManagementRequest $request): JsonResponse
    {
        try {
            $item = $this->service->create($request->validated());

            return response()->json([
                'message' => 'Customer created',
                'data' => new CustomerManagementResource($item),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Customer create failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to create customer.'], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $item = $this->service->getByUuid($uuid);

            return response()->json([
                'message' => 'Customer fetched',
                'data' => new CustomerManagementResource($item),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Customer not found.'], 404);
        } catch (Throwable $e) {
            Log::error('Customer fetch failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to fetch customer.'], 500);
        }
    }

    public function update(CustomerManagementRequest $request, $uuid): JsonResponse
    {
        try {
            $item = $this->service->update($uuid, $request->validated());

            return response()->json([
                'message' => 'Customer updated',
                'data' => new CustomerManagementResource($item),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Customer not found.'], 404);
        } catch (Throwable $e) {
            Log::error('Customer update failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to update customer.'], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $this->service->delete($uuid);

            return response()->json(['message' => 'Customer deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Customer not found.'], 404);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            Log::error('Customer delete failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to delete customer.'], 500);
        }
    }
}
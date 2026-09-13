<?php

namespace App\Http\Controllers\SupplierManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Services\SupplierManagement\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    public function __construct(
        protected SupplierService $supplierService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'search' => $request->input('search'),
                'per_page' => $request->integer('per_page', 20),
            ];

            if ($request->has('status')) {
                $filters['status'] = $request->boolean('status');
            }

            $suppliers = $this->supplierService->getSuppliers($filters);

            return response()->json([
                'status' => 'success',
                'code' => 200,
                'message' => 'Suppliers retrieved successfully.',
                'data' => SupplierResource::collection($suppliers),
                'errors' => null,
                'pagination' => [
                    'current_page' => $suppliers->currentPage(),
                    'last_page' => $suppliers->lastPage(),
                    'per_page' => $suppliers->perPage(),
                    'total' => $suppliers->total(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to retrieve suppliers.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 500,
                'message' => 'Unable to retrieve suppliers.',
                'data' => null,
                'errors' => null,
                'pagination' => null,
            ], 500);
        }
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        try {
            $supplier = $this->supplierService->createSupplier(
                $request->validated()
            );

            return response()->json([
                'status' => 'success',
                'code' => 201,
                'message' => 'Supplier created successfully.',
                'data' => new SupplierResource($supplier),
                'errors' => null,
                'pagination' => null,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to create supplier.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 500,
                'message' => 'Failed to create supplier.',
                'data' => null,
                'errors' => null,
                'pagination' => null,
            ], 500);
        }
    }

    public function show(string $uuid): JsonResponse
    {
        try {
            $supplier = $this->supplierService->getSupplier($uuid);

            if (!$supplier) {
                return response()->json([
                    'status' => 'error',
                    'code' => 404,
                    'message' => 'Supplier not found.',
                    'data' => null,
                    'errors' => null,
                    'pagination' => null,
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'code' => 200,
                'message' => 'Supplier retrieved successfully.',
                'data' => new SupplierResource($supplier),
                'errors' => null,
                'pagination' => null,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to retrieve supplier.', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 500,
                'message' => 'Unable to retrieve supplier.',
                'data' => null,
                'errors' => null,
                'pagination' => null,
            ], 500);
        }
    }

    public function update(
        SupplierRequest $request,
        string $uuid
    ): JsonResponse {
        try {
            $supplier = $this->supplierService->getSupplier($uuid);

            if (!$supplier) {
                return response()->json([
                    'status' => 'error',
                    'code' => 404,
                    'message' => 'Supplier not found.',
                    'data' => null,
                    'errors' => null,
                    'pagination' => null,
                ], 404);
            }

            $supplier = $this->supplierService->updateSupplier(
                $supplier,
                $request->validated()
            );

            return response()->json([
                'status' => 'success',
                'code' => 200,
                'message' => 'Supplier updated successfully.',
                'data' => new SupplierResource($supplier),
                'errors' => null,
                'pagination' => null,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to update supplier.', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 500,
                'message' => 'Failed to update supplier.',
                'data' => null,
                'errors' => null,
                'pagination' => null,
            ], 500);
        }
    }

    public function destroy(string $uuid): JsonResponse
    {
        try {
            $supplier = $this->supplierService->getSupplier($uuid);

            if (!$supplier) {
                return response()->json([
                    'status' => 'error',
                    'code' => 404,
                    'message' => 'Supplier not found.',
                    'data' => null,
                    'errors' => null,
                    'pagination' => null,
                ], 404);
            }

            $this->supplierService->deleteSupplier($supplier);

            return response()->json([
                'status' => 'success',
                'code' => 200,
                'message' => 'Supplier deleted successfully.',
                'data' => null,
                'errors' => null,
                'pagination' => null,
            ], 200);
        } catch (\RuntimeException $e) {
            Log::warning('Supplier deletion blocked.', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 422,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
                'pagination' => null,
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to delete supplier.', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 500,
                'message' => 'Failed to delete supplier.',
                'data' => null,
                'errors' => null,
                'pagination' => null,
            ], 500);
        }
    }
}
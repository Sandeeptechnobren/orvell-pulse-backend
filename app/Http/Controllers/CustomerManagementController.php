<?php

namespace App\Http\Controllers;

use App\Http\Request\CustomerManagementRequest;
use App\Http\Resources\CustomerManagementResource;
use App\Services\CustomerManagementService;

/**
 * @OA\Tag(
 *     name="Customer Management",
 *     description="Customer management APIs"
 * )
 */
class CustomerManagementController extends Controller
{
    protected $service;

    public function __construct(CustomerManagementService $service)
    {
        $this->service = $service;
    }

    /**
     * Get Customers List
     *
     * @OA\Get(
     *     path="/api/customers",
     *     tags={"Customer Management"},
     *     summary="Get customers list",
     *     description="Fetch all customers",
     *     @OA\Response(
     *         response=200,
     *         description="Customers fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Customers fetched"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/CustomerManagement")
     *             )
     *         )
     *     )
     * )
     */
    public function index()
    {
        return response()->json([
            'message' => 'Customers fetched',
            'data' => CustomerManagementResource::collection(
                $this->service->list()
            )
        ]);
    }

    /**
     * Create Customer
     *
     * @OA\Post(
     *     path="/api/customers",
     *     tags={"Customer Management"},
     *     summary="Create customer",
     *     description="Create a new customer",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", example="9876543210")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Customer created",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Customer created"),
     *             @OA\Property(property="data", ref="#/components/schemas/CustomerManagement")
     *         )
     *     )
     * )
     */
    public function store(CustomerManagementRequest $request)
    {
        $item = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Customer created',
            'data' => new CustomerManagementResource($item)
        ], 201);
    }

    /**
     * Get Customer by UUID
     *
     * @OA\Get(
     *     path="/api/customers/{uuid}",
     *     tags={"Customer Management"},
     *     summary="Get customer details",
     *     description="Fetch customer by UUID",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="Customer UUID",
     *         @OA\Schema(type="string", example="550e8400-e29b-41d4-a716-446655440000")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Customer fetched",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Customer fetched"),
     *             @OA\Property(property="data", ref="#/components/schemas/CustomerManagement")
     *         )
     *     )
     * )
     */
    public function show($uuid)
    {
        $item = $this->service->getByUuid($uuid);

        return response()->json([
            'message' => 'Customer fetched',
            'data' => new CustomerManagementResource($item)
        ]);
    }

    /**
     * Update Customer
     *
     * @OA\Put(
     *     path="/api/customers/{uuid}",
     *     tags={"Customer Management"},
     *     summary="Update customer",
     *     description="Update customer by UUID",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", example="9876543210")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Customer updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Customer updated"),
     *             @OA\Property(property="data", ref="#/components/schemas/CustomerManagement")
     *         )
     *     )
     * )
     */
    public function update(CustomerManagementRequest $request, $uuid)
    {
        $item = $this->service->update($uuid, $request->validated());

        return response()->json([
            'message' => 'Customer updated',
            'data' => new CustomerManagementResource($item)
        ]);
    }

    /**
     * Delete Customer
     *
     * @OA\Delete(
     *     path="/api/customers/{uuid}",
     *     tags={"Customer Management"},
     *     summary="Delete customer",
     *     description="Delete customer by UUID",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Customer deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Customer deleted")
     *         )
     *     )
     * )
     */
    public function destroy($uuid)
    {
        $this->service->delete($uuid);

        return response()->json([
            'message' => 'Customer deleted'
        ]);
    }
}

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

    /**
     * @OA\Get(
     *     path="/api/customer/list",
     *     tags={"Customers"},
     * security={{"bearerAuth":{}}},
     *     summary="Get all customers",
     *     @OA\Response(
     *         response=200,
     *         description="Customers fetched",
     *     )
     * )
     */
    public function index()
    {
        return response()->json([
            'message' => 'Customers fetched',
            'data' => CustomerManagementResource::collection($this->service->list())
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/customer/add",
     *     tags={"Customers"},
     * security={{"bearerAuth":{}}},
     *     summary="Create customer",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "name": "Rahul",
     *                 "email": "rahul@example.com",
     *                 "phone": "9876543210"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Customer created",
     *     )
     * )
     */
    public function store(CustomerManagementRequest $request)
    {
        $item = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Customer created',
            'data' => new CustomerManagementResource($item)
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/customer/show/{uuid}",
     *     tags={"Customers"},
     * security={{"bearerAuth":{}}},
     *     summary="Get customer by uuid",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="Customer UUID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Customer fetched"
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
     * @OA\Put(
     *     path="/api/customer/update/{uuid}",
     *     tags={"Customers"},
     * security={{"bearerAuth":{}}},
     *     summary="Update customer",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="Customer UUID"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "name": "Updated Name",
     *                 "email": "updated@example.com",
     *                 "phone": "9988776655"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Customer updated"
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
     * @OA\Delete(
     *     path="/api/customer/delete/{uuid}",
     *     tags={"Customers"},
     * security={{"bearerAuth":{}}},
     *     summary="Delete customer",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="Customer UUID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Customer deleted"
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


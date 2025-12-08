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
/**
 * @OA\Info(
 *     title="Test API",
 *     version="1.0.0"
 * )
 */
    /**
     * @OA\Get(
     *     path="api/customer/list",
     *     tags={"Stock Management"},
     *     summary="Get all stock items",
     *     @OA\Response(
     *         response=200,
     *         description="Item categories fetched"
     *     )
     * )
     */
    public function index()
    {
        return response()->json([
            'message' => 'Item categories fetched',
            'data' => StockManagementResource::collection($this->service->list())
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/stock",
     *     tags={"Stock Management"},
     *     summary="Create a new stock item",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                "name": "Laptop",
     *                "quantity": 10,
     *                "description": "Office laptops"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Item category created"
     *     )
     * )
     */
    public function store(StockManagementRequest $request)
    {
        $item = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Item category created',
            'data' => new StockManagementResource($item)
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/stock/{uuid}",
     *     tags={"Stock Management"},
     *     summary="Get a single stock item",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="UUID of the stock item"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Item category fetched"
     *     )
     * )
     */
    public function show($uuid)
    {
        $item = $this->service->getByUuid($uuid);

        return response()->json([
            'message' => 'Item category fetched',
            'data' => new StockManagementResource($item)
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/stock/{uuid}",
     *     tags={"Stock Management"},
     *     summary="Update a stock item",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="UUID of the stock item"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             example={
     *                "name": "Updated Laptop",
     *                "quantity": 20,
     *                "description": "Updated description"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Item category updated"
     *     )
     * )
     */
    public function update(StockManagementRequest $request, $uuid)
    {
        $item = $this->service->updateByUuid($uuid, $request->validated());

        return response()->json([
            'message' => 'Item category updated',
            'data' => new StockManagementResource($item)
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/stock/{uuid}",
     *     tags={"Stock Management"},
     *     summary="Delete a stock item",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="UUID of the stock item"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Item category deleted"
     *     )
     * )
     */
    public function destroy($uuid)
    {
        $this->service->deleteByUuid($uuid);

        return response()->json([
            'message' => 'Item category deleted'
        ]);
    }
}

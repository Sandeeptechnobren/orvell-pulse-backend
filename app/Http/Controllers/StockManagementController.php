<?php

namespace App\Http\Controllers;

use App\Services\StockManagementService;
use App\Http\Request\StockManagementRequest;
use App\Http\Resources\StockManagementResource;
use App\Traits\ResponseTrait;

/**
 * @OA\Tag(
 *     name="Stock Management",
 *     description="Stock management APIs"
 * )
 */
class StockManagementController extends Controller
{
    use ResponseTrait;

    protected $service;

    public function __construct(StockManagementService $service)
    {
        $this->service = $service;
    }

    /**
     * Get Stock List
     *
     * @OA\Get(
     *     path="/api/stocks",
     *     tags={"Stock Management"},
     *     summary="Get stock list",
     *     description="Fetch all stock records",
     *     @OA\Response(
     *         response=200,
     *         description="Stock list fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Stock list fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/StockManagement")
     *             )
     *         )
     *     )
     * )
     */
    public function index()
    {
        $stock = $this->service->list();

        return $this->success(
            'Stock list fetched successfully',
            $stock
        );
    }

    /**
     * Create Stock
     *
     * @OA\Post(
     *     path="/api/stocks",
     *     tags={"Stock Management"},
     *     summary="Create stock",
     *     description="Create a new stock entry",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"product_name","quantity"},
     *             @OA\Property(property="product_name", type="string", example="iPhone 15"),
     *             @OA\Property(property="quantity", type="integer", example=50),
     *             @OA\Property(property="price", type="number", example=79999)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Stock created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Stock created successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/StockManagement")
     *         )
     *     )
     * )
     */
    public function store(StockManagementRequest $request)
    {
        $stock = $this->service->create($request->validated());

        return $this->success(
            'Stock created successfully',
            new StockManagementResource($stock),
            201
        );
    }

    /**
     * Get Stock by UUID
     *
     * @OA\Get(
     *     path="/api/stocks/{uuid}",
     *     tags={"Stock Management"},
     *     summary="Get stock details",
     *     description="Fetch stock by UUID",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", example="550e8400-e29b-41d4-a716-446655440000")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Stock fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Stock fetched successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/StockManagement")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Stock not found"
     *     )
     * )
     */
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

    /**
     * Update Stock
     *
     * @OA\Put(
     *     path="/api/stocks/{uuid}",
     *     tags={"Stock Management"},
     *     summary="Update stock",
     *     description="Update stock by UUID",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"product_name","quantity"},
     *             @OA\Property(property="product_name", type="string", example="iPhone 15"),
     *             @OA\Property(property="quantity", type="integer", example=60),
     *             @OA\Property(property="price", type="number", example=84999)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Stock updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Stock updated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/StockManagement")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Stock not found"
     *     )
     * )
     */
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

    /**
     * Delete Stock
     *
     * @OA\Delete(
     *     path="/api/stocks/{uuid}",
     *     tags={"Stock Management"},
     *     summary="Delete stock",
     *     description="Delete stock by UUID",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Stock deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Stock deleted successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Stock not found"
     *     )
     * )
     */
    public function destroy($uuid)
    {
        if (!$this->service->delete($uuid)) {
            return $this->error('Stock not found', [], 404);
        }

        return $this->success('Stock deleted successfully');
    }
}

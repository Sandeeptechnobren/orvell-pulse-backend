<?php

namespace App\Http\Controllers;

use App\Http\Request\Item_categoryRequest;
use App\Services\Item_categoryService;
use App\Http\Resources\Item_categoryResource;

class Item_categoryController extends Controller
{
    protected $service;
    public function __construct(Item_categoryService $service)
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
     *     path="/api/item-category/list",
     *     tags={"Stock Management"},
     *     security={{"bearerAuth":{}}},
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
            'data' => Item_categoryResource::collection($this->service->list())
        ]);
    }
    /**
     * @OA\Post(
     *     path="/api/item-category/add",
     *     tags={"Stock Management"},
     *     security={{"bearerAuth":{}}},
     *     summary="Create a new stock item",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "category_name": "Electronics",
     *                 "category_type": "Main"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Item category created"
     *     )
     * )
     */
    public function store(Item_categoryRequest $request)
    {
        $item = $this->service->create($request->validated());
        return response()->json([
            'message' => 'Item category created',
            'data' => new Item_categoryResource($item)
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/item-category/show/{uuid}",
     *     tags={"Stock Management"},
     *     security={{"bearerAuth":{}}},
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
            'data' => new Item_categoryResource($item)
        ]);
    }
    /**
     * @OA\Put(
     *     path="/api/item-category/update/{uuid}",
     *     tags={"Stock Management"},
     *     security={{"bearerAuth":{}}},
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
    public function update(Item_categoryRequest $request, $uuid)
    {
        $item = $this->service->updateByUuid($uuid, $request->validated());
        return response()->json([
            'message' => 'Item category updated',
            'data' => new Item_categoryResource($item)
        ]);
    }
    /**
     * @OA\Delete(
     *     path="/api/item-category/delete/{uuid}",
     *     tags={"Stock Management"},
     *     security={{"bearerAuth":{}}},
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

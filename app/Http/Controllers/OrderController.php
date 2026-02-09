<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use App\Http\Resources\OrderResource;

class OrderController extends Controller
{
    protected OrderService $service;

    public function __construct(OrderService $service)
    {
        $this->service = $service;
    }

    /**
     * @OA\Get(
     *     path="/api/orders/list",
     *     tags={"Orders"},
     *     summary="Get all orders",
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Orders fetched successfully"
     *     )
     * )
     */
    public function index()
    {
        $orders = $this->service->list(); // must return paginate()

        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/orders/show/{uuid}",
     *     tags={"Orders"},
     *     summary="Get order by UUID",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="Order UUID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order fetched successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found"
     *     )
     * )
     */
    public function show(string $uuid)
    {
        $order = $this->service->getByUuid($uuid);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order),
        ]);
    }
}

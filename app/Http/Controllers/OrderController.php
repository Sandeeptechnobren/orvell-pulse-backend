<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use App\Traits\ResponseTrait;

class OrderController extends Controller
{
    use ResponseTrait;

    protected $service;

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
        $orders = $this->service->list();
        return $this->success(
            'Orders fetched successfully',
            OrderResource::collection($orders)
        );
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
    public function show($uuid)
    {
        $order = $this->service->getByUuid($uuid);

        if (!$order) {
            return $this->error('Order not found', [], 404);
        }

        return $this->success(
            'Order fetched successfully',
            new OrderResource($order)
        );
    }
}

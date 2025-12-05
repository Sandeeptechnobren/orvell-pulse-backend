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
    public function index()
    {
        $orders = $this->service->list();
        return $this->success(
            'Orders fetched successfully',
            OrderResource::collection($orders)
        );
    }

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

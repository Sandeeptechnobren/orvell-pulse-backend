<?php

namespace App\Services;

use App\Models\Order;

class OrderService
{
    public function list()
    {
        return Order::orderBy('id', 'desc')->get();
    }

    public function getByUuid($uuid)
    {
        return Order::where('uuid', $uuid)->first();
    }

}

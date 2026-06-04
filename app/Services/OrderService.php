<?php

namespace App\Services;
use App\Models\Order;

class OrderService
{
    public function list()
{
    return Order::with([
        'space:id,name',
        'customer:id,name,whatsapp_number',
        'product:id,name',
    ])->latest('id')->paginate(50);
}
    public function getByUuid($uuid)
    {
        return Order::where('uuid', $uuid)->first();
    }
}

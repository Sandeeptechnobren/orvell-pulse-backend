<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'uuid'          => $this->uuid,
            'order_no'      => $this->order_no,
            'customer_name' => $this->customer_name,
            'total_amount'  => $this->total_amount,
            'status'        => $this->status,
            'created_at'    => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}

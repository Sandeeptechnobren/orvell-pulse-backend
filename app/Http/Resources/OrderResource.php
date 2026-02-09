<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'space_name' => $this->space?->name,
            'customer_name' => $this->customer?->name,
            'customer_number' => $this->customer?->whatsapp_number,
            'product_name' => $this->product?->name,
            'order_amount' => (float) $this->order_amount,
            'currency' => $this->currency ?? 'GHS',
            'payment_status' => $this->payment_status,
            'order_date' => $this->created_at
                ? $this->created_at->format('d-M-Y')
                : null,

            'order_status' => $this->status,
        ];
    }
}

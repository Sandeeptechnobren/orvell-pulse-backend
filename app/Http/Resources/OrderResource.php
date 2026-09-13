<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'order_no' => $this->order_no,
            'invoice_number' => $this->invoice_code,
            'pickup_code' => $this->pickup_code,
            'pickup_status' => $this->pickup_status,
            'order_quantity' => $this->order_quantity,
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

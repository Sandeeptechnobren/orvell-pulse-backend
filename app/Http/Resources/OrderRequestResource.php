<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'request_no' => $this->request_no,
            'status' => $this->status,
            'notes' => $this->notes,
            'declined_reason' => $this->declined_reason,
            'customer' => $this->whenLoaded('customer', fn () => [
                'uuid' => $this->customer->uuid,
                'buyer_id' => $this->customer->buyer_id,
                'name' => $this->customer->name,
                'whatsapp_number' => $this->customer->whatsapp_number,
                'city' => $this->customer->city,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'category_id' => $item->category_id,
                'category_name' => $item->category?->category_name,
                'quantity' => $item->quantity,
            ])),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'uuid' => $this->order->uuid,
                'order_no' => $this->order->order_no,
                'invoice_number' => $this->order->invoice_code,
                'total_amount' => $this->order->total_amount,
                'payment_status' => $this->order->payment_status,
                'pickup_status' => $this->order->pickup_status,
            ] : null),
            'actioned_by' => $this->whenLoaded('actionedBy', fn () => $this->actionedBy?->name),
            'actioned_at' => $this->actioned_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}

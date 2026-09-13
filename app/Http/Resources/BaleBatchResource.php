<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BaleBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'arrival_date' => $this->arrival_date?->format('Y-m-d'),
            'qty_total' => $this->qty_total,
            'qty_available' => $this->qty_available,
            'qty_sold' => $this->qty_sold,
            'qty_released' => $this->qty_released,
            'qty_damaged' => $this->qty_damaged,
            'container' => $this->whenLoaded('container', fn () => [
                'id' => $this->container->id,
                'container_id' => $this->container->container_id,
            ]),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'category_name' => $this->category->category_name,
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}

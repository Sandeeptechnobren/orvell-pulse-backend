<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContainerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'container_id' => $this->container_id,
            'status' => $this->status,
            'arrival_date' => $this->arrival_date?->format('Y-m-d'),
            'received_date' => $this->received_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'bales_count' => $this->whenCounted('bales'),
            'bales' => $this->whenLoaded('bales', fn () => $this->bales->map(fn ($bale) => [
                'id' => $bale->id,
                'bale_id' => $bale->bale_id,
                'category' => $bale->category,
                'status' => $bale->status,
            ])),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
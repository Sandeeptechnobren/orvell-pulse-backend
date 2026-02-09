<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockManagementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'           => $this->uuid,
            'code'           => $this->sku,
            'item_name'      => $this->name,

            'item_category'  => $this->whenLoaded('itemCategory', function () {
                return [
                    'id'   => $this->itemCategory->id,
                    'category_name' => $this->itemCategory->category_name,
                ];
            }),

            // 'item_category'  => $this->category->id,
            'available_unit' => (int) $this->stock,
            'original_price' => number_format((float) $this->price, 2, '.', ''),
            'discount_price' => 'Not Applicable',
            'offer'          => $this->offer ?? 'NaN',
            'created_by'     => $this->created_by,
            'updated_by'     => $this->updated_by,
            'created_at'     => $this->created_at?->toDateTimeString(),
            'updated_at'     => $this->updated_at?->toDateTimeString(),
        ];
    }
}

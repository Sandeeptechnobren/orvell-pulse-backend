<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class Item_categoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' =>$this->id,
            'uuid' => $this->uuid,
            'code' => $this->code,
            'category_name' => $this->category_name,
            'category_type' => $this->category_type,
        ];
    }
} 

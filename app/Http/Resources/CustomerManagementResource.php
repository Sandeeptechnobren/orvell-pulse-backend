<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerManagementResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'phone_no' => $this->phone_no,
            'whatsapp_no' => $this->whatsapp_no,
            'address_1' => $this->address_1,
            'address_2' => $this->address_2,
            'district' => $this->district,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
        ];
    }
}

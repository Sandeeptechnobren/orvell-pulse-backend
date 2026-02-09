<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerManagementResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'customer_code'                =>$this->id,
            'uuid'              => $this->uuid,
            'name'              => $this->name,
            'phone_no'             => $this->whatsapp_number,
            'whatsapp_no'   => $this->whatsapp_number,
            'email'             => $this->email,
            'address_1'           => $this->address,
            'address_2'           => $this->country,
            'zip_code'           => $this->zipcode,
            'onboarding_status' => $this->onboarding_status,
            'meta'              => $this->meta ? json_decode($this->meta, true) : null,
        ];
    }
}

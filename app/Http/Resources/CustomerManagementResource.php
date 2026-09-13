<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerManagementResource extends JsonResource
{
    private const ONBOARDING_LABELS = [
        0 => 'incomplete',
        1 => 'completed',
    ];

    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'buyer_id' => $this->buyer_id,
            'name' => $this->name,
            'whatsapp_number' => $this->whatsapp_number,
            'wa_id' => $this->wa_id,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'zipcode' => $this->zipcode,
            'preferred_categories' => $this->preferred_categories,
            'onboarding_status' => $this->onboarding_status,
            'onboarding_status_label' => self::ONBOARDING_LABELS[$this->onboarding_status] ?? 'unknown',
            'orders_count' => $this->whenCounted('orders'),
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
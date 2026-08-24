<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WhatsappMessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'       => $this->uuid,
            'direction'  => $this->direction,
            'from'       => $this->sender_wa_id,
            'to'         => $this->recipient_wa_id,
            'body'       => $this->message_body,
            'type'       => $this->message_type,
            'media_url'  => $this->media_url,
            'status'     => $this->status,
            'instance'   => $this->chatterly_instance_id,
            'sent_at'    => $this->created_at?->toDateTimeString(),
        ];
    }
}

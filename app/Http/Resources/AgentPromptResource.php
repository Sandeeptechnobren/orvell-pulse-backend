<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AgentPromptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'               => $this->uuid,
            'prompt_for'         => $this->prompt_for,
            'prompt_description' => $this->prompt_description,
            'updated_at'         => $this->updated_at?->toDateTimeString(),
        ];
    }
}

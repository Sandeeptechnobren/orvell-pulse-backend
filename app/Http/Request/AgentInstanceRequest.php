<?php

namespace App\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

class AgentInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_type' => 'required|in:admin,customer',
        ];
    }

    public function agentType(): string
    {
        return $this->input('agent_type');
    }
}

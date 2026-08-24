<?php

namespace App\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

class AgentPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prompt_for'         => 'required|in:admin,customer',
            'prompt_description' => $this->isMethod('get')
                ? 'nullable|string'
                : 'required|string|max:10000',
        ];
    }

    public function promptFor(): string
    {
        return $this->input('prompt_for');
    }

    public function promptDescription(): ?string
    {
        return $this->input('prompt_description');
    }
}

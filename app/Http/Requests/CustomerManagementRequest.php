<?php

namespace App\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $uuid = $this->route('uuid');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:150'],
            'whatsapp_number' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:20',
                'regex:/^\+?[0-9]{9,15}$/',
                Rule::unique('customers', 'whatsapp_number')
                    ->ignore($uuid, 'uuid')
                    ->whereNull('deleted_at'),
            ],
            'wa_id' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'wa_id')
                    ->ignore($uuid, 'uuid')
                    ->whereNull('deleted_at'),
            ],
            'email' => [
                'nullable',
                'email',
                'max:150',
                Rule::unique('customers', 'email')
                    ->ignore($uuid, 'uuid')
                    ->whereNull('deleted_at'),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'zipcode' => ['nullable', 'string', 'max:20'],
            'preferred_categories' => ['nullable', 'array'],
            'preferred_categories.*' => ['integer', 'exists:item_category,id'],
            'onboarding_status' => ['nullable', 'integer', 'between:0,2'],
            'meta' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_number.regex' => 'WhatsApp number must contain only digits (9-15), optionally starting with +.',
            'whatsapp_number.unique' => 'A customer with this WhatsApp number already exists.',
            'email.unique' => 'A customer with this email already exists.',
            'preferred_categories.*.exists' => 'One of the selected categories does not exist.',
        ];
    }
}

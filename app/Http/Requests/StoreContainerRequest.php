<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'container_id' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('tbl_containers', 'container_id'),
            ],
            'supplier_id' => ['required', 'integer', 'exists:tbl_suppliers,id'],
            'arrival_date' => ['required', 'date'],
            'received_date' => ['nullable', 'date', 'after_or_equal:arrival_date'],
            'status' => [
                'required',
                Rule::in(['in_transit', 'arrived', 'received', 'closed']),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'The selected supplier does not exist.',
            'container_id.unique' => 'This container ID is already registered.',
            'received_date.after_or_equal' => 'Received date cannot be before the arrival date.',
        ];
    }
}
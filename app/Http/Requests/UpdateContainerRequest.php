<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'container_id' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('tbl_containers', 'container_id')
                    ->ignore($this->route('container')),
            ],
            'supplier_id' => ['sometimes', 'integer', 'exists:tbl_suppliers,id'],
            'arrival_date' => ['sometimes', 'date'],
            'received_date' => ['nullable', 'date', 'after_or_equal:arrival_date'],
            'status' => [
                'sometimes',
                Rule::in(['in_transit', 'arrived', 'received', 'closed']),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
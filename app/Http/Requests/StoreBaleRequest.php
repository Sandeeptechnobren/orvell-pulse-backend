<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'container_id' => ['required', 'integer', 'exists:tbl_containers,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.category_id' => ['required', 'integer', 'exists:item_category,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'container_id.exists' => 'The selected container does not exist.',
            'lines.required' => 'Add at least one line of bales.',
            'lines.*.category_id.exists' => 'One of the selected categories does not exist.',
            'lines.*.quantity.max' => 'A single line cannot exceed 1000 bales.',
        ];
    }
}
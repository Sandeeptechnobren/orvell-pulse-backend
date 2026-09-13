<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConvertOrderRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_category_id' => ['required', 'integer', 'exists:item_category,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Priced lines are required to convert a request into a sale.',
            'lines.*.unit_price.required' => 'Every line needs a unit price set by staff.',
            'lines.*.unit_price.min' => 'Unit price must be greater than zero.',
        ];
    }
}

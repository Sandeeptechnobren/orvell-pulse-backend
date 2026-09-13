<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', 'exists:tbl_categories,id'],
            'status' => [
                'sometimes',
                Rule::in(['in_stock', 'sold', 'released', 'damaged']),
            ],
        ];
    }
}
<?php

namespace App\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

class StockManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'nullable|string|max:255|unique:item_category,code',
            'category_name' => 'required|string|max:255',
            'category_type' => 'required|string|max:255',
        ];
    }
}

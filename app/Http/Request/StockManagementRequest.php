<?php

namespace App\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
         $uuid = $this->route('uuid');
        return [
            // 'code' => 'nullable|string|max:255|unique:item_category,code',
            'code' => ['nullable','string','max:255',Rule::unique('item_category', 'code')->ignore($uuid, 'uuid')],
            'category_name' => 'required|string|max:255',
            'category_type' => 'required|string|max:255',
        ];
    }
}

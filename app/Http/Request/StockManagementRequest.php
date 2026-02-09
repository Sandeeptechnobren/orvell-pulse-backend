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
                'sku'               => 'nullable|string|max:50',
                'item_name'         => 'required|string|max:255',
                'item_category_id'  => 'nullable|exists:item_category,id',
                'available_unit'    => 'required|integer|min:0',
                'original_price'    => 'required|numeric|min:0',
                'discount_price'    => 'nullable|numeric|min:0',
                'offer'             => 'nullable|string|max:255',
            ];


    }
}

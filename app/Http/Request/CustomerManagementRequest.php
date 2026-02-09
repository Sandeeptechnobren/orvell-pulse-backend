<?php

namespace App\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

class CustomerManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'phone_no' => 'nullable|string|max:20',
            'whatsapp_no' => 'nullable|string|max:20',
            'address_1' => 'nullable|string|max:255',
            'address_2' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
        ];
    }
}

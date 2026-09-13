<?php


namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('post')) {
            $this->merge([
                'supplier_code' => $this->generateSupplierCode(),
            ]);
        }
    }
    private function generateSupplierCode(): string
    {
        $year = now()->format('Y');

        $prefix = "ORV{$year}";

        $lastSupplier = Supplier::query()
            ->where('supplier_code', 'like', "{$prefix}%")
            ->orderByDesc('supplier_code')
            ->first();

        if ($lastSupplier) {
            $lastNumber = (int) Str::after(
                $lastSupplier->supplier_code,
                $prefix
            );

            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad(
            $nextNumber,
            4,
            '0',
            STR_PAD_LEFT
        );
    }

    public function rules(): array
    {
        return [
            'supplier_code' => [
                'required',
                'string',
                'max:50',
                'unique:tbl_suppliers,supplier_code',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'phone_no' => [
                'nullable',
                'string',
                'max:30',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'address_1' => [
                'nullable',
                'string',
                'max:255',
            ],

            'address_2' => [
                'nullable',
                'string',
                'max:255',
            ],

            'district' => [
                'nullable',
                'string',
                'max:100',
            ],

            'state' => [
                'nullable',
                'string',
                'max:100',
            ],

            'zip_code' => [
                'nullable',
                'string',
                'max:20',
            ],

            'country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'status' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_code.required' => 'Supplier code is required.',
            'supplier_code.unique' => 'Supplier code already exists.',
            'name.required' => 'Supplier name is required.',
            'email.email' => 'Please provide a valid email address.',
            'status.boolean' => 'Status must be true or false.',
        ];
    }
}


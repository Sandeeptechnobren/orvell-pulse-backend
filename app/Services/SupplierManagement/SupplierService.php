<?php

// namespace App\Services\SupplierManagement;

// use App\Models\Supplier;
// use Illuminate\Contracts\Pagination\LengthAwarePaginator;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Str;

// class SupplierService
// {
//     /**
//      * Get supplier list.
//      */
//     public function getSuppliers(array $filters): LengthAwarePaginator
//     {
//         $query = Supplier::query();

//         if (!empty($filters['search'])) {
//             $search = $filters['search'];

//             $query->where(function ($q) use ($search) {
//                 $q->where('supplier_code', 'ILIKE', "%{$search}%")
//                     ->orWhere('name', 'ILIKE', "%{$search}%")
//                     ->orWhere('phone_no', 'ILIKE', "%{$search}%")
//                     ->orWhere('email', 'ILIKE', "%{$search}%")
//                     ->orWhere('district', 'ILIKE', "%{$search}%")
//                     ->orWhere('state', 'ILIKE', "%{$search}%");
//             });
//         }

//         if (array_key_exists('status', $filters)) {
//             $query->where('status', $filters['status']);
//         }

//         $perPage = $filters['per_page'] ?? 20;

//         return $query
//             ->orderByDesc('id')
//             ->paginate($perPage);
//     }

//     /**
//      * Create supplier.
//      */
//     public function createSupplier(array $data): Supplier
//     {
        
//         return DB::transaction(function () use ($data) {

//             return Supplier::create([
//                 'uuid' => (string) Str::uuid(),
//                 'supplier_code' => $data['supplier_code'],
//                 'name' => $data['name'],
//                 'phone_no' => $data['phone_no'] ?? null,
//                 'email' => $data['email'] ?? null,
//                 'address_1' => $data['address_1'] ?? null,
//                 'address_2' => $data['address_2'] ?? null,
//                 'district' => $data['district'] ?? null,
//                 'state' => $data['state'] ?? null,
//                 'zip_code' => $data['zip_code'] ?? null,
//                 'country' => $data['country'] ?? null,
//                 'status' => $data['status'] ?? true,
//             ]);
//         });
//     }

//     /**
//      * Get supplier by UUID.
//      */
//     public function getSupplier(string $uuid): ?Supplier
//     {
//         return Supplier::where('uuid', $uuid)->first();
//     }

//     /**
//      * Update supplier.
//      */
//     public function updateSupplier(
//         Supplier $supplier,
//         array $data
//     ): Supplier {
//         return DB::transaction(function () use ($supplier, $data) {

//             $supplier->update([
//                 'supplier_code' => $data['supplier_code'],
//                 'name' => $data['name'],
//                 'phone_no' => $data['phone_no'] ?? null,
//                 'email' => $data['email'] ?? null,
//                 'address_1' => $data['address_1'] ?? null,
//                 'address_2' => $data['address_2'] ?? null,
//                 'district' => $data['district'] ?? null,
//                 'state' => $data['state'] ?? null,
//                 'zip_code' => $data['zip_code'] ?? null,
//                 'country' => $data['country'] ?? null,
//                 'status' => $data['status'] ?? $supplier->status,
//             ]);

//             return $supplier->fresh();
//         });
//     }

//     /**
//      * Delete supplier.
//      */
//     public function deleteSupplier(Supplier $supplier): void
//     {
//         DB::transaction(function () use ($supplier) {

//             if (
//                 $supplier->containers()->exists() ||
//                 $supplier->bales()->exists()
//             ) {
//                 throw new \RuntimeException(
//                     'Supplier cannot be deleted because it is linked to containers or bales.'
//                 );
//             }

//             $supplier->delete();
//         });
//     }
// }

namespace App\Services\SupplierManagement;

use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SupplierService
{
    /**
     * Get supplier list.
     */
    public function getSuppliers(array $filters): LengthAwarePaginator
    {
        try {
            $query = Supplier::query();

            if (!empty($filters['search'])) {
                $search = $filters['search'];

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'supplier_code',
                        'ILIKE',
                        "%{$search}%"
                    )
                        ->orWhere(
                            'name',
                            'ILIKE',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'phone_no',
                            'ILIKE',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'email',
                            'ILIKE',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'district',
                            'ILIKE',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'state',
                            'ILIKE',
                            "%{$search}%"
                        );
                });
            }

            if (array_key_exists('status', $filters)) {
                $query->where('status', $filters['status']);
            }

            $perPage = $filters['per_page'] ?? 20;

            return $query
                ->orderByDesc('id')
                ->paginate($perPage);
        } catch (Throwable $e) {
            Log::error('Failed to retrieve suppliers.', [
                'filters' => $filters,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Create supplier.
     */
    public function createSupplier(array $data): Supplier
    {
        DB::beginTransaction();

        try {
            $supplier = Supplier::create([
                'uuid' => (string) Str::uuid(),
                'supplier_code' => $data['supplier_code'],
                'name' => $data['name'],
                'phone_no' => $data['phone_no'] ?? null,
                'email' => $data['email'] ?? null,
                'address_1' => $data['address_1'] ?? null,
                'address_2' => $data['address_2'] ?? null,
                'district' => $data['district'] ?? null,
                'state' => $data['state'] ?? null,
                'zip_code' => $data['zip_code'] ?? null,
                'country' => $data['country'] ?? null,
                'status' => $data['status'] ?? true,
            ]);

            DB::commit();

            return $supplier;
        } catch (Throwable $e) {
            DB::rollBack();
            dd($e);
            Log::error('Failed to create supplier.', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get supplier by UUID.
     */
    public function getSupplier(string $uuid): ?Supplier
    {
        try {
            return Supplier::where('uuid', $uuid)->first();
        } catch (Throwable $e) {
            Log::error('Failed to retrieve supplier.', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Update supplier.
     */
    public function updateSupplier(
        Supplier $supplier,
        array $data
    ): Supplier {
        DB::beginTransaction();

        try {
            $supplier->update([
                'supplier_code' => $data['supplier_code'],
                'name' => $data['name'],
                'phone_no' => $data['phone_no'] ?? null,
                'email' => $data['email'] ?? null,
                'address_1' => $data['address_1'] ?? null,
                'address_2' => $data['address_2'] ?? null,
                'district' => $data['district'] ?? null,
                'state' => $data['state'] ?? null,
                'zip_code' => $data['zip_code'] ?? null,
                'country' => $data['country'] ?? null,
                'status' => $data['status'] ?? $supplier->status,
            ]);

            DB::commit();

            return $supplier->fresh();
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Failed to update supplier.', [
                'uuid' => $supplier->uuid,
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete supplier.
     */
    public function deleteSupplier(Supplier $supplier): void
    {
        DB::beginTransaction();

        try {
            if (
                $supplier->containers()->exists()
                || $supplier->bales()->exists()
            ) {
                throw new RuntimeException(
                    'Supplier cannot be deleted because it is linked to containers or bales.'
                );
            }

            $supplier->delete();

            DB::commit();
        } catch (RuntimeException $e) {
            DB::rollBack();

            Log::warning('Supplier deletion blocked.', [
                'uuid' => $supplier->uuid,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Failed to delete supplier.', [
                'uuid' => $supplier->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
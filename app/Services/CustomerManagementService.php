<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerManagementService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Customer::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('buyer_id', 'like', "%{$search}%")
                    ->orWhere('whatsapp_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (isset($filters['onboarding_status']) && $filters['onboarding_status'] !== '') {
            $query->where('onboarding_status', (int) $filters['onboarding_status']);
        }

        if (!empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        return $query
            ->withCount('orders')
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $data['whatsapp_number'] = $this->normalizePhone($data['whatsapp_number']);
            $data['onboarding_status'] = $data['onboarding_status'] ?? 0;

            return Customer::create($data);
        });
    }

    public function getByUuid(string $uuid): Customer
    {
        $customer = Customer::where('uuid', $uuid)
            ->withCount('orders')
            ->first();

        if (!$customer) {
            throw new ModelNotFoundException("Customer not found.");
        }

        return $customer;
    }

    public function findByWhatsappNumber(string $phone): ?Customer
    {
        return Customer::where('whatsapp_number', $this->normalizePhone($phone))
            ->orWhere('wa_id', $phone)
            ->first();
    }

    public function update(string $uuid, array $data): Customer
    {
        return DB::transaction(function () use ($uuid, $data) {
            $customer = $this->getByUuid($uuid);

            if (isset($data['whatsapp_number'])) {
                $data['whatsapp_number'] = $this->normalizePhone($data['whatsapp_number']);
            }

            unset($data['uuid'], $data['buyer_id']);

            $customer->update($data);

            return $customer->fresh()->loadCount('orders');
        });
    }

    public function delete(string $uuid): void
    {
        DB::transaction(function () use ($uuid) {
            $customer = $this->getByUuid($uuid);

            if ($customer->orders()->exists()) {
                throw new RuntimeException(
                    "Customer {$customer->buyer_id} has orders and cannot be deleted. Their history must be retained."
                );
            }

            $customer->delete();
        });
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // Ghana local format (0XXXXXXXXX) -> international (233XXXXXXXXX)
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '233' . substr($digits, 1);
        }

        return $digits;
    }
}
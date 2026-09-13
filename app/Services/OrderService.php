<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Order::query()->with(['customer', 'invoice']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (!empty($filters['pickup_status'])) {
            $query->where('pickup_status', $filters['pickup_status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_no', 'like', "%{$search}%")
                    ->orWhere('invoice_code', 'like', "%{$search}%")
                    ->orWhere('pickup_code', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getByUuid(string $uuid): ?Order
    {
        return Order::where('uuid', $uuid)
            ->with(['customer', 'invoice.items', 'payments'])
            ->first();
    }
}

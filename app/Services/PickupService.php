<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PickupService
{
    public function __construct(
        protected SaleService $saleService
    ) {
    }

    /**
     * Validate a pickup code without releasing anything. Pickup codes never
     * expire, so this only reports the order's current state.
     */
    public function validatePickupCode(string $pickupCode, ?int $companyId = null): array
    {
        $order = $this->saleService->getSaleByPickupCode($pickupCode);

        if (!$order) {
            throw ValidationException::withMessages([
                'pickup_code' => ["No order found for pickup code {$pickupCode}."],
            ]);
        }

        return [
            'valid'          => true,
            'order_no'       => $order->order_no,
            'invoice_number' => $order->invoice_code,
            'customer'       => $order->customer?->name,
            'buyer_id'       => $order->customer?->buyer_id,
            'total_amount'   => $order->total_amount,
            'payment_status' => $order->payment_status,
            'pickup_status'  => $order->pickup_status,
            'releasable'     => $order->payment_status === 'paid' && $order->pickup_status !== 'released',
            'items'          => $order->invoice?->items?->map(fn ($item) => [
                'description' => $item->item_description,
                'quantity'    => $item->quantity,
            ])->values(),
        ];
    }

    /**
     * Release the order's bales against a pickup code, logging staff identity.
     */
    public function releaseOrderBales(
        string $pickupCode,
        ?int $staffId = null,
        ?int $companyId = null,
        ?string $notes = null
    ): array {
        $order = $this->saleService->confirmPickup($pickupCode, $staffId);

        return [
            'success' => true,
            'message' => "Goods for order {$order->order_no} released to {$order->customer?->name}.",
            'data'    => [
                'order_no'      => $order->order_no,
                'pickup_status' => $order->pickup_status,
                'released_at'   => now()->toDateTimeString(),
                'released_by'   => $staffId,
            ],
        ];
    }
}

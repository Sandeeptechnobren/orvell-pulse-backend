<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Invoice;
use App\Models\Bale;
use App\Models\Release;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PickupService
{
    protected AuditService $auditService;
    protected InventoryService $inventoryService;

    public function __construct(AuditService $auditService, InventoryService $inventoryService)
    {
        $this->auditService = $auditService;
        $this->inventoryService = $inventoryService;
    }

    /**
     * Validate a pickup code and return order details, buyer info, and reserved bales ready for warehouse release.
     *
     * @param string $pickupCode
     * @param int|null $companyId
     * @return array
     * @throws ValidationException
     */
    public function validatePickupCode(string $pickupCode, ?int $companyId = null): array
    {
        $pickupCode = trim(strtoupper($pickupCode));

        $query = Order::where('pickup_code', $pickupCode)
            ->with(['buyer', 'invoice.items', 'company', 'reservedBales']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $order = $query->first();

        if (!$order) {
            throw ValidationException::withMessages([
                'pickup_code' => ['Invalid pickup code or unauthorized for this company.'],
            ]);
        }

        if ($order->pickup_status === 'completed' || $order->pickup_status === 'picked_up') {
            throw ValidationException::withMessages([
                'pickup_code' => ["Pickup code {$pickupCode} has already been consumed and goods released."],
            ]);
        }

        // Fetch all reserved Bales for this order
        $reservedBales = Bale::where('reserved_order_id', $order->id)
            ->where('status', 'reserved')
            ->get();

        $invoice = $order->invoice;

        return [
            'valid'               => true,
            'pickup_code'         => $pickupCode,
            'order'               => $order,
            'buyer'               => $order->buyer,
            'invoice'             => $invoice,
            'payment_status'      => $order->payment_status,
            'pickup_status'       => $order->pickup_status,
            'reserved_bales_count'=> $reservedBales->count(),
            'reserved_bales'      => $reservedBales,
        ];
    }

    /**
     * Atomically process physical warehouse pickup release against a valid pickup code.
     * Transitions Bales from 'reserved' -> 'released', appends ledger transactions, and marks order completed.
     *
     * @param string $pickupCode
     * @param int|null $staffId
     * @param int|null $companyId
     * @param string|null $notes
     * @return array
     * @throws ValidationException
     */
    public function releaseOrderBales(string $pickupCode, ?int $staffId = null, ?int $companyId = null, ?string $notes = null): array
    {
        $pickupCode = trim(strtoupper($pickupCode));

        return DB::transaction(function () use ($pickupCode, $staffId, $companyId, $notes) {
            // Pessimistic lock on Order row
            $query = Order::where('pickup_code', $pickupCode)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $order = $query->first();

            if (!$order) {
                throw ValidationException::withMessages([
                    'pickup_code' => ['Invalid pickup code or unauthorized for this company.'],
                ]);
            }

            if ($order->pickup_status === 'completed' || $order->pickup_status === 'picked_up') {
                throw ValidationException::withMessages([
                    'pickup_code' => ["Pickup code {$pickupCode} has already been completed."],
                ]);
            }

            // Find all reserved Bales tied to this order
            $reservedBales = Bale::where('reserved_order_id', $order->id)
                ->where('company_id', $order->company_id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get();

            if ($reservedBales->isEmpty()) {
                throw ValidationException::withMessages([
                    'bales' => ["No reserved bales found for Order #{$order->order_no}."],
                ]);
            }

            $authUser = auth()->user();
            $effectiveStaffId = $staffId ?? ($authUser instanceof User ? $authUser->id : null);

            $baleIds = $reservedBales->pluck('id')->toArray();

            // 1. Physically release the Bales through InventoryService
            $releasedBales = $this->inventoryService->releaseBales($baleIds, $effectiveStaffId, $order->company_id);

            // 2. Create immutable Release entity
            $release = Release::create([
                'order_id'    => $order->id,
                'invoice_id'  => $order->invoice?->id,
                'buyer_id'    => $order->buyer_id,
                'company_id'  => $order->company_id,
                'client_id'   => $order->client_id,
                'pickup_code' => $pickupCode,
                'released_by' => $effectiveStaffId,
                'released_at' => now(),
                'status'      => 'completed',
                'notes'       => $notes ?? 'Physical warehouse dispatch upon pickup presentation',
            ]);

            // 3. Update Order status
            $order->update([
                'pickup_status' => 'completed',
                'status'        => 'completed',
            ]);

            // 4. Audit Log
            $this->auditService->log(
                action: 'bale.pickup_completed',
                auditable: $release,
                newValues: [
                    'release_code'   => $release->release_code,
                    'pickup_code'    => $pickupCode,
                    'order_no'       => $order->order_no,
                    'released_bales' => count($releasedBales),
                ],
                companyId: $order->company_id,
                userId: $effectiveStaffId
            );

            return [
                'success'        => true,
                'message'        => "Goods successfully released for Order #{$order->order_no}",
                'release'        => $release,
                'order'          => $order->fresh(),
                'released_bales' => $releasedBales,
            ];
        });
    }
}

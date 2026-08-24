<?php

namespace App\Services;

use App\Models\Bale;
use App\Models\InventoryTransaction;
use App\Models\StockManagement;
use App\Services\AuditService;
use App\Services\BaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    protected AuditService $auditService;
    protected BaleService $baleService;

    public function __construct(AuditService $auditService, BaleService $baleService)
    {
        $this->auditService = $auditService;
        $this->baleService = $baleService;
    }

    /**
     * Atomically reserve a physical Bale with pessimistic concurrency locking.
     *
     * @param int $baleId
     * @param int|null $orderId
     * @param int|null $staffId
     * @param int|null $companyId
     * @return Bale
     * @throws ValidationException
     */
    public function reserveBale(int $baleId, ?int $orderId = null, ?int $staffId = null, ?int $companyId = null): Bale
    {
        $results = $this->reserveBales([$baleId], $orderId, $staffId, $companyId);
        return $results[0];
    }

    /**
     * Atomically reserve multiple physical Bales with pessimistic concurrency locking.
     * Prevents race conditions, double reservations, and negative stock.
     *
     * @param array $baleIds
     * @param int|null $orderId
     * @param int|null $staffId
     * @param int|null $companyId
     * @return array<Bale>
     * @throws ValidationException
     */
    public function reserveBales(array $baleIds, ?int $orderId = null, ?int $staffId = null, ?int $companyId = null): array
    {
        return DB::transaction(function () use ($baleIds, $orderId, $staffId, $companyId) {
            if (empty($baleIds)) {
                throw ValidationException::withMessages([
                    'bales' => ['No bale IDs provided for reservation.'],
                ]);
            }

            // Pessimistic lock on all requested Bale rows
            $query = Bale::whereIn('id', $baleIds)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $bales = $query->get();

            if ($bales->count() !== count($baleIds)) {
                throw ValidationException::withMessages([
                    'bales' => ['One or more requested bales do not exist or belong to another company.'],
                ]);
            }

            $affectedCategories = [];
            $reservedBales = [];
            $authUser = auth()->user();
            $effectiveStaffId = $staffId ?? ($authUser instanceof \App\Models\User ? $authUser->id : null);

            foreach ($bales as $bale) {
                if ($bale->status !== 'available') {
                    throw ValidationException::withMessages([
                        'bales' => ["Bale {$bale->bale_code} is currently '{$bale->status}' and cannot be reserved."],
                    ]);
                }

                $bale->update([
                    'status'            => 'reserved',
                    'reserved_order_id' => $orderId,
                ]);

                // Record immutable ledger entry
                InventoryTransaction::create([
                    'company_id'       => $bale->company_id,
                    'client_id'        => $bale->client_id,
                    'container_id'     => $bale->container_id,
                    'bale_id'          => $bale->id,
                    'item_category_id' => $bale->item_category_id,
                    'transaction_type' => 'sale_reservation',
                    'quantity'         => -1,
                    'unit_price'       => $bale->selling_price,
                    'reference_type'   => 'App\Models\Order',
                    'reference_id'     => $orderId,
                    'staff_id'         => $effectiveStaffId,
                    'notes'            => "Bale reserved for Order ID #{$orderId}",
                ]);

                if ($bale->item_category_id) {
                    $affectedCategories[$bale->item_category_id] = $bale->company_id;
                }

                $reservedBales[] = $bale;

                $this->auditService->log(
                    action: 'bale.reserve',
                    auditable: $bale,
                    newValues: ['status' => 'reserved', 'reserved_order_id' => $orderId],
                    companyId: $bale->company_id,
                    userId: $effectiveStaffId
                );
            }

            // Sync derived aggregate for each category
            foreach ($affectedCategories as $catId => $compId) {
                $this->baleService->syncCategoryProductStock($catId, $compId);
            }

            return $reservedBales;
        });
    }

    /**
     * Atomically release a physical Bale upon buyer pickup presentation.
     *
     * @param int $baleId
     * @param int|null $staffId
     * @param int|null $companyId
     * @return Bale
     * @throws ValidationException
     */
    public function releaseBale(int $baleId, ?int $staffId = null, ?int $companyId = null): Bale
    {
        $results = $this->releaseBales([$baleId], $staffId, $companyId);
        return $results[0];
    }

    /**
     * Atomically release multiple physical Bales upon pickup.
     *
     * @param array $baleIds
     * @param int|null $staffId
     * @param int|null $companyId
     * @return array<Bale>
     * @throws ValidationException
     */
    public function releaseBales(array $baleIds, ?int $staffId = null, ?int $companyId = null): array
    {
        return DB::transaction(function () use ($baleIds, $staffId, $companyId) {
            $query = Bale::whereIn('id', $baleIds)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $bales = $query->get();

            if ($bales->count() !== count($baleIds)) {
                throw ValidationException::withMessages([
                    'bales' => ['One or more bales not found or unauthorized.'],
                ]);
            }

            $releasedBales = [];

            foreach ($bales as $bale) {
                if ($bale->status === 'released' || $bale->status === 'sold') {
                    throw ValidationException::withMessages([
                        'bales' => ["Bale {$bale->bale_code} has already been physically released."],
                    ]);
                }

                if ($bale->status !== 'reserved' && $bale->status !== 'available') {
                    throw ValidationException::withMessages([
                        'bales' => ["Bale {$bale->bale_code} cannot be released from status '{$bale->status}'."],
                    ]);
                }

                $wasAvailable = ($bale->status === 'available');

                $authUser = auth()->user();
                $effectiveStaffId = $staffId ?? ($authUser instanceof \App\Models\User ? $authUser->id : null);

                $bale->update([
                    'status'      => 'released',
                    'released_at' => now(),
                    'released_by' => $effectiveStaffId,
                ]);

                // Append to ledger
                InventoryTransaction::create([
                    'company_id'       => $bale->company_id,
                    'client_id'        => $bale->client_id,
                    'container_id'     => $bale->container_id,
                    'bale_id'          => $bale->id,
                    'item_category_id' => $bale->item_category_id,
                    'transaction_type' => 'pickup_release',
                    'quantity'         => $wasAvailable ? -1 : 0,
                    'unit_price'       => $bale->selling_price,
                    'reference_type'   => 'App\Models\Release',
                    'reference_id'     => $bale->reserved_order_id,
                    'staff_id'         => $effectiveStaffId,
                    'notes'            => 'Physical outward warehouse release',
                ]);

                // If released directly from available, decrement catalogue stock
                if ($wasAvailable && $bale->item_category_id) {
                    $this->baleService->syncCategoryProductStock($bale->item_category_id, $bale->company_id);
                }

                $releasedBales[] = $bale;

                $this->auditService->log(
                    action: 'bale.release',
                    auditable: $bale,
                    newValues: ['status' => 'released', 'released_at' => now()],
                    companyId: $bale->company_id,
                    userId: $effectiveStaffId
                );
            }

            return $releasedBales;
        });
    }

    /**
     * Cancel an active reservation and restore the Bale to available status.
     *
     * @param int $baleId
     * @param int|null $staffId
     * @param int|null $companyId
     * @return Bale
     * @throws ValidationException
     */
    public function cancelReservation(int $baleId, ?int $staffId = null, ?int $companyId = null): Bale
    {
        return DB::transaction(function () use ($baleId, $staffId, $companyId) {
            $query = Bale::where('id', $baleId)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $bale = $query->first();

            if (!$bale) {
                throw ValidationException::withMessages([
                    'bale_id' => ['Bale not found or unauthorized.'],
                ]);
            }

            if ($bale->status !== 'reserved') {
                throw ValidationException::withMessages([
                    'status' => ["Bale {$bale->bale_code} is not in 'reserved' status."],
                ]);
            }

            $bale->update([
                'status'            => 'available',
                'reserved_order_id' => null,
            ]);

            $authUser = auth()->user();
            $effectiveStaffId = $staffId ?? ($authUser instanceof \App\Models\User ? $authUser->id : null);

            // Append return transaction
            InventoryTransaction::create([
                'company_id'       => $bale->company_id,
                'client_id'        => $bale->client_id,
                'container_id'     => $bale->container_id,
                'bale_id'          => $bale->id,
                'item_category_id' => $bale->item_category_id,
                'transaction_type' => 'return',
                'quantity'         => 1,
                'unit_price'       => $bale->cost_price,
                'staff_id'         => $effectiveStaffId,
                'notes'            => 'Reservation cancelled / stock returned to available',
            ]);

            if ($bale->item_category_id) {
                $this->baleService->syncCategoryProductStock($bale->item_category_id, $bale->company_id);
            }

            $this->auditService->log(
                action: 'bale.reservation_cancelled',
                auditable: $bale,
                newValues: ['status' => 'available'],
                companyId: $bale->company_id,
                userId: $effectiveStaffId
            );

            return $bale;
        });
    }

    /**
     * Controlled adjustment for damaged or recovered stock.
     *
     * @param int $baleId
     * @param string $newStatus ('damaged', 'available')
     * @param string $reason
     * @param int|null $staffId
     * @param int|null $companyId
     * @return Bale
     * @throws ValidationException
     */
    public function adjustBale(int $baleId, string $newStatus, string $reason, ?int $staffId = null, ?int $companyId = null): Bale
    {
        return DB::transaction(function () use ($baleId, $newStatus, $reason, $staffId, $companyId) {
            $query = Bale::where('id', $baleId)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $bale = $query->first();

            if (!$bale) {
                throw ValidationException::withMessages([
                    'bale_id' => ['Bale not found or unauthorized.'],
                ]);
            }

            $oldStatus = $bale->status;

            if ($oldStatus === $newStatus) {
                return $bale;
            }

            $allowedTransitions = [
                'available' => ['damaged'],
                'damaged'   => ['available'],
            ];

            if (!isset($allowedTransitions[$oldStatus]) || !in_array($newStatus, $allowedTransitions[$oldStatus])) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot adjust bale from '{$oldStatus}' to '{$newStatus}'."],
                ]);
            }

            $bale->update(['status' => $newStatus]);

            $txType = ($newStatus === 'damaged') ? 'adjustment_deduction' : 'adjustment_addition';
            $qty = ($newStatus === 'damaged') ? -1 : 1;

            $authUser = auth()->user();
            $effectiveStaffId = $staffId ?? ($authUser instanceof \App\Models\User ? $authUser->id : null);

            InventoryTransaction::create([
                'company_id'       => $bale->company_id,
                'client_id'        => $bale->client_id,
                'container_id'     => $bale->container_id,
                'bale_id'          => $bale->id,
                'item_category_id' => $bale->item_category_id,
                'transaction_type' => $txType,
                'quantity'         => $qty,
                'unit_price'       => $bale->cost_price,
                'staff_id'         => $effectiveStaffId,
                'notes'            => "Stock adjustment: {$reason}",
            ]);

            if ($bale->item_category_id) {
                $this->baleService->syncCategoryProductStock($bale->item_category_id, $bale->company_id);
            }

            $this->auditService->log(
                action: 'bale.adjustment',
                auditable: $bale,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus, 'reason' => $reason],
                companyId: $bale->company_id,
                userId: $effectiveStaffId
            );

            return $bale;
        });
    }

    /**
     * Get stock counts by category and company.
     *
     * @param int $categoryId
     * @param int $companyId
     * @return array
     */
    public function getStockBreakdown(int $categoryId): array
    {
        $bales = Bale::where('item_category_id', $categoryId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'category_id'     => $categoryId,
            // 'company_id'      => $companyId,
            'available_stock' => $bales['available'] ?? 0,
            'reserved_stock'  => $bales['reserved'] ?? 0,
            'released_stock'  => $bales['released'] ?? 0,
            'sold_stock'      => $bales['sold'] ?? 0,
            'damaged_stock'   => $bales['damaged'] ?? 0,
            'total_on_hand'   => ($bales['available'] ?? 0) + ($bales['reserved'] ?? 0),
        ];
    }
}

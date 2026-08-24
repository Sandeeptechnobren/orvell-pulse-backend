<?php

namespace App\Services;

use App\Models\Bale;
use App\Models\StockManagement;
use App\Models\Item_category;
use App\Models\Company;
use App\Services\AuditService;
use App\Services\BaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    protected AuditService $auditService;
    protected BaleService $baleService;

    public function __construct(AuditService $auditService, BaleService $baleService)
    {
        $this->auditService = $auditService;
        $this->baleService = $baleService;
    }

    /**
     * Verify stock integrity by comparing derived catalogue stock (products.stock)
     * against the authoritative physical available Bales count.
     * Follows the Detect -> Report -> Audit rule (strictly read-only; no silent mutations).
     *
     * @param int|null $companyId
     * @return array
     */
    public function verifyStockIntegrity(?int $companyId = null): array
    {
        $companies = $companyId
            ? Company::where('id', $companyId)->get()
            : Company::where('is_active', true)->get();

        $categories = Item_category::all();

        $discrepancies = [];
        $totalChecked = 0;

        foreach ($companies as $company) {
            foreach ($categories as $category) {
                $totalChecked++;

                $derivedStock = (int) StockManagement::where('category', $category->id)
                    ->where('company_id', $company->id)
                    ->sum('stock');

                $actualAvailableBales = Bale::where('item_category_id', $category->id)
                    ->where('company_id', $company->id)
                    ->where('status', 'available')
                    ->count();

                $actualReservedBales = Bale::where('item_category_id', $category->id)
                    ->where('company_id', $company->id)
                    ->where('status', 'reserved')
                    ->count();

                $variance = $derivedStock - $actualAvailableBales;

                if ($variance !== 0) {
                    $item = [
                        'company_id'             => $company->id,
                        'company_name'           => $company->company_name,
                        'category_id'            => $category->id,
                        'category_name'          => $category->category_name,
                        'derived_stock'          => $derivedStock,
                        'actual_available_bales' => $actualAvailableBales,
                        'actual_reserved_bales'  => $actualReservedBales,
                        'variance'               => $variance,
                        'detected_at'            => now()->toIso8601String(),
                    ];

                    $discrepancies[] = $item;

                    $this->auditService->log(
                        action: 'reconciliation.stock_discrepancy_detected',
                        auditable: $category,
                        newValues: $item,
                        companyId: $company->id
                    );

                    Log::warning("INVENTORY DISCREPANCY DETECTED for Company {$company->id}, Category {$category->id}: derived={$derivedStock}, actual={$actualAvailableBales}, variance={$variance}");
                }
            }
        }

        $isBalanced = empty($discrepancies);

        return [
            'status'                   => $isBalanced ? 'balanced' : 'discrepancy_found',
            'total_categories_checked' => $totalChecked,
            'discrepancy_count'        => count($discrepancies),
            'discrepancies'            => $discrepancies,
            'verified_at'              => now()->toIso8601String(),
        ];
    }

    /**
     * Explicit, authorized stock repair: synchronizes products.stock to match authoritative physical Bales count.
     * Follows the Authorized Repair -> Audit Repair lifecycle.
     *
     * @param int $categoryId
     * @param int $companyId
     * @param string|null $reason
     * @param string|null $actorName
     * @param int|null $userId
     * @return array
     */
    public function repairStockDiscrepancy(
        int $categoryId,
        int $companyId,
        ?string $reason = null,
        ?string $actorName = null,
        ?int $userId = null
    ): array {
        return DB::transaction(function () use ($categoryId, $companyId, $reason, $actorName, $userId) {
            $beforeStock = (int) StockManagement::where('category', $categoryId)
                ->where('company_id', $companyId)
                ->sum('stock');

            $correctedStock = $this->baleService->syncCategoryProductStock($categoryId, $companyId);
            $varianceFixed = $beforeStock - $correctedStock;

            $this->auditService->log(
                action: 'reconciliation.stock_repaired',
                auditable: "ItemCategory:{$categoryId}",
                oldValues: [
                    'derived_stock' => $beforeStock,
                ],
                newValues: [
                    'corrected_stock'           => $correctedStock,
                    'calculated_physical_bales' => $correctedStock,
                    'variance_fixed'            => $varianceFixed,
                    'reason'                    => $reason ?? 'Authorized manual reconciliation repair',
                    'actor_name'                => $actorName ?? auth()->user()?->name ?? 'Authorized Admin',
                ],
                companyId: $companyId,
                actorName: $actorName,
                userId: $userId ?? auth()->id()
            );

            return [
                'status'          => true,
                'category_id'     => $categoryId,
                'company_id'      => $companyId,
                'previous_stock'  => $beforeStock,
                'corrected_stock' => $correctedStock,
                'variance_fixed'  => $varianceFixed,
                'reason'          => $reason ?? 'Authorized manual reconciliation repair',
                'repaired_at'     => now()->toIso8601String(),
            ];
        });
    }
}

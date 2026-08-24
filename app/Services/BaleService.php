<?php

namespace App\Services;

use App\Models\Bale;
use App\Models\Container;
use App\Models\Item_category;
use App\Models\InventoryTransaction;
use App\Models\StockManagement;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BaleService
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Create a single physical Bale record with ledger tracking and stock synchronization.
     *
     * @param array $data
     * @param int|null $companyId
     * @return Bale
     * @throws ValidationException
     */
    public function createBale(array $data, ?int $companyId = null): Bale
    {
        return DB::transaction(function () use ($data, $companyId) {
            $targetCompanyId = $companyId ?? ($data['company_id'] ?? auth()->user()?->company_id);

            if (!$targetCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['A valid company_id is required for bale operations.'],
                ]);
            }

            // Validate Container belongs to target company
            if (!empty($data['container_id'])) {
                $container = Container::where('id', $data['container_id'])->first();
                if (!$container || ($container->company_id && $container->company_id != $targetCompanyId)) {
                    throw ValidationException::withMessages([
                        'container_id' => ['Container does not exist or belongs to another company.'],
                    ]);
                }
            }

            // Validate Category exists
            if (!empty($data['item_category_id'])) {
                $categoryExists = Item_category::where('id', $data['item_category_id'])->exists();
                if (!$categoryExists) {
                    throw ValidationException::withMessages([
                        'item_category_id' => ['Specified garment category does not exist.'],
                    ]);
                }
            }

            $bale = Bale::create([
                'bale_code'        => $data['bale_code'] ?? null,
                'container_id'     => $data['container_id'] ?? null,
                'bale_batch_id'    => $data['bale_batch_id'] ?? null,
                'item_category_id' => $data['item_category_id'] ?? null,
                'company_id'       => $targetCompanyId,
                'client_id'        => $data['client_id'] ?? auth()->user()?->client_id,
                'supplier_name'    => $data['supplier_name'] ?? null,
                'arrival_date'     => $data['arrival_date'] ?? now()->toDateString(),
                'cost_price'       => $data['cost_price'] ?? 0.00,
                'selling_price'    => $data['selling_price'] ?? 0.00,
                'weight_kg'        => $data['weight_kg'] ?? null,
                'status'           => 'available',
                'qr_code'          => $data['qr_code'] ?? null,
            ]);

            // Create ledger entry
            InventoryTransaction::create([
                'company_id'       => $targetCompanyId,
                'client_id'        => $bale->client_id,
                'container_id'     => $bale->container_id,
                'bale_id'          => $bale->id,
                'item_category_id' => $bale->item_category_id,
                'transaction_type' => 'bale_addition',
                'quantity'         => 1,
                'unit_price'       => $bale->cost_price,
                'reference_type'   => Bale::class,
                'reference_id'     => $bale->id,
                'staff_id'         => auth()->id(),
                'notes'            => 'Direct physical bale creation',
            ]);

            // Sync derived aggregate
            if ($bale->item_category_id) {
                $this->syncCategoryProductStock($bale->item_category_id, $targetCompanyId);
            }

            $this->auditService->logCreate($bale, 'bale.create', $targetCompanyId);

            return $bale;
        });
    }

    /**
     * Retrieve a Bale by its unique code with company isolation.
     *
     * @param string $baleCode
     * @param int|null $companyId
     * @return Bale|null
     */
    public function getBaleByCode(string $baleCode, ?int $companyId = null): ?Bale
    {
        $query = Bale::where('bale_code', $baleCode)->with(['container', 'category', 'company']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->first();
    }

    /**
     * Update allowed metadata on a physical Bale (prices, weight, supplier, QR).
     *
     * @param int $baleId
     * @param array $data
     * @param int|null $companyId
     * @return Bale
     * @throws ValidationException
     */
    public function updateBaleMetadata(int $baleId, array $data, ?int $companyId = null): Bale
    {
        return DB::transaction(function () use ($baleId, $data, $companyId) {
            $query = Bale::where('id', $baleId)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $bale = $query->first();

            if (!$bale) {
                throw ValidationException::withMessages([
                    'bale_id' => ['Bale not found or inaccessible for this company.'],
                ]);
            }

            $oldValues = $bale->toArray();

            // Disallow arbitrary status changes via metadata endpoint
            unset($data['status'], $data['company_id'], $data['uuid'], $data['bale_code']);

            $bale->update($data);

            $this->auditService->logUpdate($bale, 'bale.update_metadata', $oldValues, $bale->toArray(), $bale->company_id);

            return $bale;
        });
    }

    /**
     * Recalculate derived products.stock aggregate from physical available Bales.
     *
     * @param int $categoryId
     * @param int $companyId
     * @return int
     */
    public function syncCategoryProductStock(int $categoryId, int $companyId): int
    {
        $availableCount = Bale::where('item_category_id', $categoryId)
            ->where('company_id', $companyId)
            ->where('status', 'available')
            ->count();

        $product = StockManagement::where('category', $categoryId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if ($product) {
            $product->update(['stock' => $availableCount]);
        } else {
            $category = Item_category::find($categoryId);
            StockManagement::create([
                'company_id' => $companyId,
                'category'   => $categoryId,
                'name'       => $category?->category_name ?? ('Category ' . $categoryId),
                'stock'      => $availableCount,
                'type'       => 'physical',
                'is_active'  => true,
            ]);
        }

        return $availableCount;
    }
}

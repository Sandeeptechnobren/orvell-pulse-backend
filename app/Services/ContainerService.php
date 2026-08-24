<?php

namespace App\Services;

use App\Models\Container;
use App\Models\Bale;
use App\Models\BaleBatch;
use App\Models\Item_category;
use App\Models\InventoryTransaction;
use App\Models\StockManagement;
use App\Services\AuditService;
use App\Services\BaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContainerService
{
    protected AuditService $auditService;
    protected BaleService $baleService;

    public function __construct(AuditService $auditService, BaleService $baleService)
    {
        $this->auditService = $auditService;
        $this->baleService = $baleService;
    }

    /**
     * Register a new shipping container record.
     *
     * @param array $data
     * @param int|null $companyId
     * @return Container
     * @throws ValidationException
     */
    public function createContainer(array $data, ?int $companyId = null): Container
    {
        return DB::transaction(function () use ($data, $companyId) {
            $targetCompanyId = $companyId ?? ($data['company_id'] ?? auth()->user()?->company_id);

            if (!$targetCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company ID is required to register a container.'],
                ]);
            }

            $container = Container::create([
                'container_number' => $data['container_number'] ?? null,
                'supplier_name'    => $data['supplier_name'] ?? 'International Textile Supplier',
                'company_id'       => $targetCompanyId,
                'client_id'        => $data['client_id'] ?? auth()->user()?->client_id,
                'space_id'         => $data['space_id'] ?? null,
                'arrival_date'     => $data['arrival_date'] ?? now()->toDateString(),
                'status'           => $data['status'] ?? 'docked',
                'total_bales'      => $data['total_bales'] ?? 0,
                'remaining_bales'  => $data['remaining_bales'] ?? 0,
                'shipping_ref'     => $data['shipping_ref'] ?? null,
                'notes'            => $data['notes'] ?? null,
            ]);

            $this->auditService->logCreate($container, 'container.create', $targetCompanyId);

            return $container;
        });
    }

    /**
     * Process container inward arrival: unpack manifest batches into individual physical Bales,
     * record immutable ledger transactions, and synchronize derived catalogue stock.
     *
     * @param int $containerId
     * @param array $manifest Array of batch entries: [['item_category_id' => 1, 'quantity' => 50, 'cost_price' => 100, 'selling_price' => 200]]
     * @param int|null $staffId
     * @param int|null $companyId
     * @return Container
     * @throws ValidationException
     */
    public function processContainerArrival(int $containerId, array $manifest = [], ?int $staffId = null, ?int $companyId = null): Container
    {
        return DB::transaction(function () use ($containerId, $manifest, $staffId, $companyId) {
            $query = Container::where('id', $containerId)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $container = $query->first();

            if (!$container) {
                throw ValidationException::withMessages([
                    'container_id' => ['Container not found or unauthorized.'],
                ]);
            }

            // Idempotency: If already in_stock and already has instantiated bales matching manifest, avoid duplicate creation
            $existingBalesCount = Bale::where('container_id', $container->id)->count();
            if ($container->status === 'in_stock' && $existingBalesCount > 0 && empty($manifest)) {
                return $container;
            }

            $totalNewBales = 0;
            $affectedCategories = [];

            foreach ($manifest as $manifestItem) {
                $categoryId = $manifestItem['item_category_id'] ?? null;
                $quantity = (int) ($manifestItem['quantity'] ?? 0);
                $costPrice = (float) ($manifestItem['cost_price'] ?? 0.0);
                $sellingPrice = (float) ($manifestItem['selling_price'] ?? 0.0);
                $supplierName = $manifestItem['supplier_name'] ?? $container->supplier_name;
                $weightKg = isset($manifestItem['weight_kg']) ? (float) $manifestItem['weight_kg'] : null;

                if (!$categoryId || $quantity <= 0) {
                    continue;
                }

                // Verify category exists
                if (!Item_category::where('id', $categoryId)->exists()) {
                    throw ValidationException::withMessages([
                        'manifest' => ["Category ID {$categoryId} in manifest does not exist."],
                    ]);
                }

                $affectedCategories[$categoryId] = true;

                // 1. Create BaleBatch manifest record
                $batch = BaleBatch::create([
                    'container_id'       => $container->id,
                    'item_category_id'   => $categoryId,
                    'company_id'         => $container->company_id,
                    'total_quantity'     => $quantity,
                    'remaining_quantity' => $quantity,
                    'unit_price'         => $costPrice,
                ]);

                // 2. Instantiate individual physical Bales
                for ($i = 1; $i <= $quantity; $i++) {
                    $bale = Bale::create([
                        'container_id'     => $container->id,
                        'bale_batch_id'    => $batch->id,
                        'item_category_id' => $categoryId,
                        'company_id'       => $container->company_id,
                        'client_id'        => $container->client_id,
                        'supplier_name'    => $supplierName,
                        'arrival_date'     => $container->arrival_date ?? now()->toDateString(),
                        'cost_price'       => $costPrice,
                        'selling_price'    => $sellingPrice,
                        'weight_kg'        => $weightKg,
                        'status'           => 'available',
                    ]);

                    // 3. Append to immutable inventory ledger
                    InventoryTransaction::create([
                        'company_id'       => $container->company_id,
                        'client_id'        => $container->client_id,
                        'container_id'     => $container->id,
                        'bale_id'          => $bale->id,
                        'item_category_id' => $categoryId,
                        'transaction_type' => 'container_arrival',
                        'quantity'         => 1,
                        'unit_price'       => $costPrice,
                        'reference_type'   => Container::class,
                        'reference_id'     => $container->id,
                        'staff_id'         => $staffId ?? auth()->id(),
                        'notes'            => "Inward arrival from Container {$container->container_number}",
                    ]);

                    $totalNewBales++;
                }
            }

            // 4. Update container status and totals
            $totalBales = Bale::where('container_id', $container->id)->count();
            $availableBales = Bale::where('container_id', $container->id)->where('status', 'available')->count();

            $container->update([
                'status'          => 'in_stock',
                'total_bales'     => $totalBales,
                'remaining_bales' => $availableBales,
            ]);

            // 5. Synchronize derived products.stock aggregate for all affected categories
            foreach (array_keys($affectedCategories) as $catId) {
                $this->baleService->syncCategoryProductStock($catId, $container->company_id);
            }

            $this->auditService->log(
                action: 'container.arrival_processed',
                auditable: $container,
                newValues: [
                    'container_number' => $container->container_number,
                    'total_bales'      => $totalBales,
                    'new_bales_added'  => $totalNewBales,
                ],
                companyId: $container->company_id,
                userId: $staffId ?? auth()->id()
            );

            return $container;
        });
    }

    /**
     * Retrieve a Container with its relations.
     */
    public function getContainer(int $containerId, ?int $companyId = null): ?Container
    {
        $query = Container::where('id', $containerId)->with(['bales', 'batches', 'company']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->first();
    }
}

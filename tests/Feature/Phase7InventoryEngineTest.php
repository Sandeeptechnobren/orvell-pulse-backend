<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use App\Models\Company;
use App\Models\Container;
use App\Models\Bale;
use App\Models\BaleBatch;
use App\Models\Item_category;
use App\Models\StockManagement;
use App\Models\InventoryTransaction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\BaleService;
use App\Services\ContainerService;
use App\Services\InventoryService;
use App\Services\ReconciliationService;

class Phase7InventoryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected Item_category $categoryDresses;
    protected Item_category $categoryJeans;
    protected StockManagement $productDressesA;

    protected BaleService $baleService;
    protected ContainerService $containerService;
    protected InventoryService $inventoryService;
    protected ReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baleService = app(BaleService::class);
        $this->containerService = app(ContainerService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->reconciliationService = app(ReconciliationService::class);

        // Setup 2 distinct companies
        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi', 'currency' => 'GHS']);

        // Setup categories
        $this->categoryDresses = Item_category::create(['category_name' => 'Summer Dresses Grade A', 'category_type' => 'Garments']);
        $this->categoryJeans = Item_category::create(['category_name' => 'Denim Jeans Grade A', 'category_type' => 'Garments']);

        // Setup derived catalogue products
        $this->productDressesA = StockManagement::create([
            'name'       => 'Summer Dresses Grade A',
            'category'   => $this->categoryDresses->id,
            'company_id' => $this->companyA->id,
            'price'      => 250.00,
            'stock'      => 0,
        ]);
    }

    public function test_bale_creation_ledger_and_unique_code(): void
    {
        $container = Container::create([
            'supplier_name' => 'EuroTex Ltd',
            'company_id'    => $this->companyA->id,
        ]);

        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 120.00,
            'selling_price'    => 250.00,
            'weight_kg'        => 45.5,
        ], $this->companyA->id);

        $this->assertNotNull($bale->uuid);
        $this->assertStringStartsWith('BALE-', $bale->bale_code);
        $this->assertEquals('available', $bale->status);
        $this->assertEquals($this->companyA->id, $bale->company_id);

        // Assert ledger entry created
        $tx = InventoryTransaction::where('bale_id', $bale->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('bale_addition', $tx->transaction_type);
        $this->assertEquals(1, $tx->quantity);

        // Assert derived stock aggregate updated
        $this->productDressesA->refresh();
        $this->assertEquals(1, $this->productDressesA->stock);

        // Assert audit log created
        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'bale.create',
            'auditable_type' => 'App\Models\Bale',
            'auditable_id'   => $bale->id,
        ]);
    }

    public function test_container_arrival_unpacking_and_idempotency(): void
    {
        $container = $this->containerService->createContainer([
            'supplier_name' => 'Hamburg Importers',
            'shipping_ref'  => 'MSKU-998877',
        ], $this->companyA->id);

        $manifest = [
            [
                'item_category_id' => $this->categoryDresses->id,
                'quantity'         => 5,
                'cost_price'       => 100.00,
                'selling_price'    => 200.00,
                'weight_kg'        => 50.0,
            ],
        ];

        $processedContainer = $this->containerService->processContainerArrival($container->id, $manifest, null, $this->companyA->id);

        $this->assertEquals('in_stock', $processedContainer->status);
        $this->assertEquals(5, $processedContainer->total_bales);
        $this->assertEquals(5, $processedContainer->remaining_bales);

        // 5 distinct physical Bales instantiated
        $this->assertEquals(5, Bale::where('container_id', $container->id)->count());

        // Derived aggregate synced to 5
        $this->productDressesA->refresh();
        $this->assertEquals(5, $this->productDressesA->stock);

        // IDEMPOTENCY TEST: Re-running arrival without manifest must NOT duplicate bales
        $reprocessed = $this->containerService->processContainerArrival($container->id, [], null, $this->companyA->id);
        $this->assertEquals(5, Bale::where('container_id', $container->id)->count());
    }

    public function test_bale_reservation_lifecycle_and_aggregate_reduction(): void
    {
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);

        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $this->productDressesA->refresh();
        $this->assertEquals(1, $this->productDressesA->stock);

        $order = \App\Models\Order::create([
            'company_id'   => $this->companyA->id,
            'total_amount' => 200.00,
        ]);

        // 1. Reserve Bale
        $reservedBale = $this->inventoryService->reserveBale($bale->id, $order->id, null, $this->companyA->id);

        $this->assertEquals('reserved', $reservedBale->status);
        $this->assertEquals($order->id, $reservedBale->reserved_order_id);

        // Derived available stock decreases to 0
        $this->productDressesA->refresh();
        $this->assertEquals(0, $this->productDressesA->stock);

        // Ledger check
        $this->assertDatabaseHas('inventory_transactions', [
            'bale_id'          => $bale->id,
            'transaction_type' => 'sale_reservation',
            'quantity'         => -1,
        ]);

        // 2. Release Bale upon pickup
        $releasedBale = $this->inventoryService->releaseBale($bale->id, null, $this->companyA->id);

        $this->assertEquals('released', $releasedBale->status);
        $this->assertNotNull($releasedBale->released_at);

        // Ledger check for release
        $this->assertDatabaseHas('inventory_transactions', [
            'bale_id'          => $bale->id,
            'transaction_type' => 'pickup_release',
        ]);

        // Derived available stock remains 0 (goods left warehouse)
        $this->productDressesA->refresh();
        $this->assertEquals(0, $this->productDressesA->stock);
    }

    public function test_duplicate_reservation_prevention_and_negative_stock(): void
    {
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);

        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        // First reservation succeeds
        $this->inventoryService->reserveBale($bale->id, null, null, $this->companyA->id);

        // Second reservation attempt on same bale MUST fail with ValidationException
        $this->expectException(ValidationException::class);
        $this->inventoryService->reserveBale($bale->id, null, null, $this->companyA->id);
    }

    public function test_duplicate_release_prevention(): void
    {
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);

        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $this->inventoryService->reserveBale($bale->id, null, null, $this->companyA->id);
        $this->inventoryService->releaseBale($bale->id, null, $this->companyA->id);

        // Attempting release a second time MUST fail
        $this->expectException(ValidationException::class);
        $this->inventoryService->releaseBale($bale->id, null, $this->companyA->id);
    }

    public function test_reservation_cancellation_restores_available_stock(): void
    {
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);

        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $this->inventoryService->reserveBale($bale->id, null, null, $this->companyA->id);
        $this->productDressesA->refresh();
        $this->assertEquals(0, $this->productDressesA->stock);

        // Cancel reservation
        $restoredBale = $this->inventoryService->cancelReservation($bale->id, null, $this->companyA->id);
        $this->assertEquals('available', $restoredBale->status);
        $this->assertNull($restoredBale->reserved_order_id);

        // Available stock restored to 1
        $this->productDressesA->refresh();
        $this->assertEquals(1, $this->productDressesA->stock);

        $this->assertDatabaseHas('inventory_transactions', [
            'bale_id'          => $bale->id,
            'transaction_type' => 'return',
            'quantity'         => 1,
        ]);
    }

    public function test_controlled_bale_adjustment_for_damaged_goods(): void
    {
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);

        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        // Adjust to damaged
        $damagedBale = $this->inventoryService->adjustBale($bale->id, 'damaged', 'Water leak in aisle 3', null, $this->companyA->id);
        $this->assertEquals('damaged', $damagedBale->status);

        // Derived stock decremented
        $this->productDressesA->refresh();
        $this->assertEquals(0, $this->productDressesA->stock);

        $this->assertDatabaseHas('inventory_transactions', [
            'bale_id'          => $bale->id,
            'transaction_type' => 'adjustment_deduction',
            'quantity'         => -1,
        ]);
    }

    public function test_multi_company_isolation_prevent_cross_company_access(): void
    {
        $containerB = Container::create(['supplier_name' => 'Supplier B', 'company_id' => $this->companyB->id]);

        $baleB = $this->baleService->createBale([
            'container_id'     => $containerB->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyB->id);

        // Company A user attempts to reserve Company B bale -> MUST FAIL
        $this->expectException(ValidationException::class);
        $this->inventoryService->reserveBale($baleB->id, null, null, $this->companyA->id);
    }

    public function test_stock_reconciliation_detects_and_repairs_discrepancy(): void
    {
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);

        $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        // Initial balanced state
        $initialCheck = $this->reconciliationService->verifyStockIntegrity($this->companyA->id);
        $this->assertEquals('balanced', $initialCheck['status']);
        $this->assertEquals(0, $initialCheck['discrepancy_count']);

        // Perturb derived products.stock manually (simulate erroneous out-of-band edit)
        $this->productDressesA->update(['stock' => 99]);

        // Discrepancy detection (Detect -> Report -> Audit rule)
        $discrepancyCheck = $this->reconciliationService->verifyStockIntegrity($this->companyA->id);
        $this->assertEquals('discrepancy_found', $discrepancyCheck['status']);
        $this->assertEquals(1, $discrepancyCheck['discrepancy_count']);
        $this->assertEquals(98, $discrepancyCheck['discrepancies'][0]['variance']); // 99 derived - 1 actual = 98

        // Audit log created for discrepancy detection
        $this->assertDatabaseHas('audit_logs', ['action' => 'reconciliation.stock_discrepancy_detected']);

        // Verify products.stock was NOT silently overwritten by verifyStockIntegrity
        $this->productDressesA->refresh();
        $this->assertEquals(99, $this->productDressesA->stock);

        // Authorized repair
        $repairResult = $this->reconciliationService->repairStockDiscrepancy($this->categoryDresses->id, $this->companyA->id, 'Admin stock repair');
        $this->assertTrue($repairResult['status']);
        $this->assertEquals(1, $repairResult['corrected_stock']);

        // Post-repair check is balanced
        $finalCheck = $this->reconciliationService->verifyStockIntegrity($this->companyA->id);
        $this->assertEquals('balanced', $finalCheck['status']);
    }

    public function test_stock_model_table_mapping_and_commercial_vs_physical_status_orthogonality(): void
    {
        // 1. Assert terminology equivalence: StockManagement table is 'products'
        $stockModel = new StockManagement();
        $this->assertEquals('products', $stockModel->getTable());

        // 2. Physical vs Commercial orthogonal separation
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $order = \App\Models\Order::create([
            'company_id'     => $this->companyA->id,
            'status'         => 'pending',
            'payment_status' => 'pending',
            'pickup_status'  => 'pending',
            'total_amount'   => 200.00,
        ]);

        $invoice = \App\Models\Invoice::create([
            'order_id'       => $order->id,
            'company_id'     => $this->companyA->id,
            'status'         => 'finalized',
            'payment_status' => 'unpaid',
            'total_amount'   => 200.00,
        ]);

        // Reserve bale for commercial order
        $reservedBale = $this->inventoryService->reserveBale($bale->id, $order->id, null, $this->companyA->id);

        // Assert physical status is 'reserved' while commercial statuses remain distinct
        $this->assertEquals('reserved', $reservedBale->status);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals('finalized', $invoice->status);
        $this->assertEquals('unpaid', $invoice->payment_status);

        // Simulate payment completion
        $invoice->update(['payment_status' => 'paid', 'paid_amount' => 200.00]);
        $order->update(['payment_status' => 'paid']);

        // Assert physical bale remains 'reserved' until pickup release
        $reservedBale->refresh();
        $this->assertEquals('reserved', $reservedBale->status);
        $this->assertEquals('pending', $order->pickup_status);

        // Simulate physical pickup release
        $releasedBale = $this->inventoryService->releaseBale($reservedBale->id, null, $this->companyA->id);
        $order->update(['pickup_status' => 'released', 'status' => 'completed']);

        // Assert physical status is now 'released' and commercial order is 'completed'
        $this->assertEquals('released', $releasedBale->status);
        $this->assertEquals('completed', $order->status);
        $this->assertEquals('released', $order->pickup_status);
    }
}

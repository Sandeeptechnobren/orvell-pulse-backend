<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use App\Models\Company;
use App\Models\Buyer;
use App\Models\Container;
use App\Models\Bale;
use App\Models\Item_category;
use App\Models\StockManagement;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\AuditLog;
use App\Services\BaleService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\SaleService;

class Phase8SalesOrdersInvoicesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected Buyer $buyerA;
    protected Buyer $buyerB;
    protected Item_category $categoryDresses;
    protected Item_category $categoryJeans;
    protected StockManagement $productDressesA;

    protected BaleService $baleService;
    protected InventoryService $inventoryService;
    protected InvoiceService $invoiceService;
    protected SaleService $saleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baleService = app(BaleService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->saleService = app(SaleService::class);

        // 2 distinct companies
        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi', 'currency' => 'GHS']);

        // Buyers with auto BUY001 format
        $this->buyerA = Buyer::create([
            'name'            => 'Kofi Mensah',
            'business_name'   => 'Mensah Clothing',
            'whatsapp_number' => '+233241112233',
            'company_id'      => $this->companyA->id,
            'category'        => 'REGULAR',
        ]);

        $this->buyerB = Buyer::create([
            'name'            => 'Abena Darko',
            'business_name'   => 'Darko Boutique',
            'whatsapp_number' => '+233242223344',
            'company_id'      => $this->companyB->id,
            'category'        => 'OCCASIONAL',
        ]);

        // Categories
        $this->categoryDresses = Item_category::create(['category_name' => 'Summer Dresses Grade A', 'category_type' => 'Garments']);
        $this->categoryJeans = Item_category::create(['category_name' => 'Denim Jeans Grade A', 'category_type' => 'Garments']);

        // Derived catalogue product
        $this->productDressesA = StockManagement::create([
            'name'       => 'Summer Dresses Grade A',
            'category'   => $this->categoryDresses->id,
            'company_id' => $this->companyA->id,
            'price'      => 300.00,
            'stock'      => 0,
        ]);
    }

    public function test_complete_sale_order_invoice_and_pickup_code_flow(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);

        $bale1 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 150.00,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $bale2 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 150.00,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $this->productDressesA->refresh();
        $this->assertEquals(2, $this->productDressesA->stock);

        // Execute complete sale
        $result = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                [
                    'bale_ids'         => [$bale1->id, $bale2->id],
                    'item_description' => 'Summer Dresses Bale',
                    'unit_price'       => 300.00,
                ],
            ],
            'payment_method' => 'cash',
            'notes'          => 'Wholesale bulk order',
        ], $this->companyA->id);

        $order = $result['order'];
        $invoice = $result['invoice'];
        $pickupCode = $result['pickup_code'];

        // 1. Order assertions
        $this->assertNotNull($order->id);
        $this->assertStringStartsWith('ORD-', $order->order_no);
        $this->assertEquals($this->buyerA->id, $order->buyer_id);
        $this->assertEquals('Kofi Mensah', $order->customer_name);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals('pending', $order->payment_status);
        $this->assertEquals('pending', $order->pickup_status);
        $this->assertEquals(2, $order->order_quantity);
        $this->assertEquals(600.00, $order->total_amount);

        // 2. Physical Bale reservation assertions (Bales MUST NOT be released)
        $bale1->refresh();
        $bale2->refresh();
        $this->assertEquals('reserved', $bale1->status);
        $this->assertEquals('reserved', $bale2->status);
        $this->assertEquals($order->id, $bale1->reserved_order_id);
        $this->assertEquals($order->id, $bale2->reserved_order_id);
        $this->assertNull($bale1->released_at); // Crucial: Not released upon sale!

        // Derived stock decremented from 2 to 0
        $this->productDressesA->refresh();
        $this->assertEquals(0, $this->productDressesA->stock);

        // 3. Invoice assertions
        $this->assertNotNull($invoice->id);
        $this->assertStringStartsWith('INV-', $invoice->invoice_number);
        $this->assertEquals('finalized', $invoice->status);
        $this->assertEquals('unpaid', $invoice->payment_status);
        $this->assertEquals(600.00, $invoice->subtotal);
        $this->assertEquals(600.00, $invoice->total_amount);
        $this->assertCount(2, $invoice->items);

        // 4. Pickup code assertions
        $this->assertNotNull($pickupCode);
        $this->assertStringStartsWith('PKP-', $pickupCode);
        $this->assertEquals($order->pickup_code, $pickupCode);

        // Assert sale searchable by pickup code
        $foundOrder = $this->saleService->getSaleByPickupCode($pickupCode, $this->companyA->id);
        $this->assertNotNull($foundOrder);
        $this->assertEquals($order->id, $foundOrder->id);

        // 5. Audit log assertions
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.create']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bale.reserve']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.create']);
    }

    public function test_category_based_automatic_bale_allocation(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);

        for ($i = 1; $i <= 3; $i++) {
            $this->baleService->createBale([
                'container_id'     => $container->id,
                'item_category_id' => $this->categoryDresses->id,
                'cost_price'       => 150.00,
                'selling_price'    => 280.00,
            ], $this->companyA->id);
        }

        // Sale requests 2 bales by category ID
        $result = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                [
                    'item_category_id' => $this->categoryDresses->id,
                    'quantity'         => 2,
                    'unit_price'       => 280.00,
                ],
            ],
        ], $this->companyA->id);

        $this->assertEquals(2, $result['order']->order_quantity);
        $this->assertEquals(560.00, $result['order']->total_amount);
        $this->assertCount(2, $result['reserved_bales']);

        // 1 bale remains available in catalogue stock
        $this->productDressesA->refresh();
        $this->assertEquals(1, $this->productDressesA->stock);
    }

    public function test_insufficient_stock_rejection_and_transactional_rollback(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);

        $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 150.00,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $initialOrdersCount = Order::count();
        $initialInvoicesCount = Invoice::count();

        // Request 5 bales when only 1 is available -> MUST FAIL with ValidationException
        $this->expectException(ValidationException::class);

        try {
            $this->saleService->createSale([
                'buyer_id' => $this->buyerA->id,
                'items'    => [
                    [
                        'item_category_id' => $this->categoryDresses->id,
                        'quantity'         => 5,
                    ],
                ],
            ], $this->companyA->id);
        } finally {
            // Verify transactional rollback: No partial order or invoice created
            $this->assertEquals($initialOrdersCount, Order::count());
            $this->assertEquals($initialInvoicesCount, Invoice::count());

            // The 1 available bale must remain available
            $this->productDressesA->refresh();
            $this->assertEquals(1, $this->productDressesA->stock);
        }
    }

    public function test_finalized_invoice_immutability_enforcement(): void
    {
        $invoice = $this->invoiceService->createInvoice([
            'buyer_id'   => $this->buyerA->id,
            'company_id' => $this->companyA->id,
            'items'      => [
                [
                    'item_description' => 'Grade A Bale',
                    'quantity'         => 1,
                    'unit_price'       => 250.00,
                ],
            ],
            'status'     => 'draft',
        ], $this->companyA->id);

        $this->assertEquals('draft', $invoice->status);

        // Can update draft invoice
        $updatedDraft = $this->invoiceService->updateDraftInvoice($invoice->id, [
            'notes' => 'Updated draft notes',
        ], $this->companyA->id);
        $this->assertEquals('Updated draft notes', $updatedDraft->notes);

        // Finalize invoice
        $finalizedInvoice = $this->invoiceService->finalizeInvoice($invoice->id, null, $this->companyA->id);
        $this->assertEquals('finalized', $finalizedInvoice->status);

        // Attempting to update a finalized invoice MUST FAIL with ValidationException
        $this->expectException(ValidationException::class);
        $this->invoiceService->updateDraftInvoice($finalizedInvoice->id, [
            'notes' => 'Illegal modification of finalized invoice',
        ], $this->companyA->id);
    }

    public function test_multi_company_isolation_on_sales_and_buyers(): void
    {
        $containerB = Container::create(['supplier_name' => 'Supplier B', 'company_id' => $this->companyB->id]);

        $baleB = $this->baleService->createBale([
            'container_id'     => $containerB->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyB->id);

        // 1. Company A user attempts to create sale with Company B's buyer -> MUST FAIL
        $this->expectException(ValidationException::class);
        $this->saleService->createSale([
            'buyer_id' => $this->buyerB->id, // Buyer from Company B!
            'items'    => [
                ['bale_ids' => [$baleB->id]],
            ],
        ], $this->companyA->id);
    }

    public function test_concurrent_bale_reservation_prevention_on_sales(): void
    {
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);

        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        // First sale succeeds
        $sale1 = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id]],
            ],
        ], $this->companyA->id);

        $this->assertNotNull($sale1['order']);

        // Second simultaneous sale attempting to reserve the exact same bale MUST fail
        $this->expectException(ValidationException::class);
        $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id]],
            ],
        ], $this->companyA->id);
    }

    public function test_api_orders_create_endpoint(): void
    {
        $container = Container::create(['supplier_name' => 'Supplier', 'company_id' => $this->companyA->id]);

        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 100.00,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $client = \App\Models\Client::create([
            'name'     => 'API Buyer Client',
            'email'    => 'apibuyer@orvell.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
        ]);

        $token = $client->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/orders/create', [
                'company_id' => $this->companyA->id,
                'buyer_id'   => $this->buyerA->id,
                'items'      => [
                    [
                        'bale_ids'         => [$bale->id],
                        'item_description' => 'Dresses Bale',
                        'unit_price'       => 200.00,
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Order created successfully',
            ]);

        $this->assertNotNull($response->json('pickup_code'));
        $this->assertNotNull($response->json('invoice'));
        $this->assertEquals('finalized', $response->json('invoice.status'));
    }
}

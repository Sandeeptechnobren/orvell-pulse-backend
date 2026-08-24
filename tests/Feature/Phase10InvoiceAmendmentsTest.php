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
use App\Models\Order;
use App\Models\Invoice;
use App\Models\InvoiceAmendmentRequest;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\BaleService;
use App\Services\SaleService;
use App\Services\InvoiceAmendmentService;
use Spatie\Permission\Models\Role;

class Phase10InvoiceAmendmentsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected Buyer $buyerA;
    protected Buyer $buyerB;
    protected User $adminUserA;
    protected User $salespersonUserA;
    protected User $adminUserB;
    protected Item_category $categoryDresses;
    protected Item_category $categoryShirts;

    protected BaleService $baleService;
    protected SaleService $saleService;
    protected InvoiceAmendmentService $amendmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baleService = app(BaleService::class);
        $this->saleService = app(SaleService::class);
        $this->amendmentService = app(InvoiceAmendmentService::class);

        // Companies
        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi', 'currency' => 'GHS']);

        // Roles
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $salesRole = Role::firstOrCreate(['name' => 'Salesperson']);

        // Users
        $this->adminUserA = User::create([
            'name'       => 'Accra Admin',
            'email'      => 'admin_accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->adminUserA->assignRole($adminRole);

        $this->salespersonUserA = User::create([
            'name'       => 'Accra Salesperson',
            'email'      => 'sales_accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->salespersonUserA->assignRole($salesRole);

        $this->adminUserB = User::create([
            'name'       => 'Kumasi Admin',
            'email'      => 'admin_kumasi@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyB->id,
        ]);
        $this->adminUserB->assignRole($adminRole);

        // Buyers
        $this->buyerA = Buyer::create([
            'name'            => 'Kofi Mensah',
            'business_name'   => 'Mensah Fashion',
            'whatsapp_number' => '+233241112233',
            'company_id'      => $this->companyA->id,
        ]);

        $this->buyerB = Buyer::create([
            'name'            => 'Abena Darko',
            'business_name'   => 'Darko Boutique',
            'whatsapp_number' => '+233242223344',
            'company_id'      => $this->companyB->id,
        ]);

        // Categories
        $this->categoryDresses = Item_category::create([
            'category_name' => 'Summer Dresses Grade A',
            'category_type' => 'Garments',
        ]);

        $this->categoryShirts = Item_category::create([
            'category_name' => 'Cotton Shirts Grade A',
            'category_type' => 'Garments',
        ]);
    }

    public function test_amendment_request_approval_flow_creates_versioned_invoice(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale1 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 400.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale1->id], 'unit_price' => 400.00],
            ],
        ], $this->companyA->id);

        $originalInvoice = $sale['invoice'];
        $originalNumber = $originalInvoice->invoice_number;

        // 1. Submit Amendment Request (give 50.00 discount)
        $request = $this->amendmentService->requestAmendment(
            $originalInvoice->id,
            [
                'reason'            => 'Special customer loyalty discount applied',
                'requested_changes' => [
                    'discount_amount' => 50.00,
                ],
            ],
            $this->salespersonUserA->id,
            $this->companyA->id
        );

        $this->assertEquals('pending', $request->status);
        $this->assertDatabaseHas('invoice_amendment_requests', ['id' => $request->id, 'status' => 'pending']);

        // 2. Admin Approves Request
        $amendedInvoice = $this->amendmentService->approveAmendment(
            $request->id,
            $this->adminUserA->id,
            $this->companyA->id
        );

        // 3. Assert Versioning & Statuses
        $this->assertEquals("{$originalNumber}-A", $amendedInvoice->invoice_number);
        $this->assertEquals('finalized', $amendedInvoice->status);
        $this->assertEquals('A', $amendedInvoice->amendment_version);
        $this->assertEquals(350.00, $amendedInvoice->total_amount); // 400 - 50 = 350

        // 4. Assert Original Invoice Marked 'amended' (Preserved immutably)
        $this->assertEquals('amended', $originalInvoice->fresh()->status);
        $this->assertEquals(400.00, $originalInvoice->fresh()->total_amount);

        // 5. Assert Order points to amended invoice
        $this->assertEquals("{$originalNumber}-A", $sale['order']->fresh()->invoice_code);
        $this->assertEquals(350.00, $sale['order']->fresh()->total_amount);

        // 6. Assert Audit Logs
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.amendment_requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.amendment_approved']);
    }

    public function test_amendment_inventory_reconciliation_on_bale_replacement(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $baleOld = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $baleNew = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryShirts->id,
            'selling_price'    => 350.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$baleOld->id], 'unit_price' => 300.00],
            ],
        ], $this->companyA->id);

        $invoice = $sale['invoice'];

        $this->assertEquals('reserved', $baleOld->fresh()->status);
        $this->assertEquals('available', $baleNew->fresh()->status);

        // Request replacement of baleOld with baleNew
        $request = $this->amendmentService->requestAmendment(
            $invoice->id,
            [
                'reason'            => 'Buyer swapped dresses for shirts',
                'requested_changes' => [
                    'items' => [
                        [
                            'bale_id'          => $baleNew->id,
                            'item_category_id' => $this->categoryShirts->id,
                            'item_description' => 'Cotton Shirts Grade A',
                            'quantity'         => 1,
                            'unit_price'       => 350.00,
                        ],
                    ],
                ],
            ],
            $this->salespersonUserA->id,
            $this->companyA->id
        );

        // Approve
        $amendedInvoice = $this->amendmentService->approveAmendment(
            $request->id,
            $this->adminUserA->id,
            $this->companyA->id
        );

        // INVENTORY RECONCILIATION VERIFICATION:
        // 1. Old Bale reservation cancelled -> back to 'available'
        $this->assertEquals('available', $baleOld->fresh()->status);
        $this->assertNull($baleOld->fresh()->reserved_order_id);

        // 2. New Bale reserved for the order -> 'reserved'
        $this->assertEquals('reserved', $baleNew->fresh()->status);
        $this->assertEquals($sale['order']->id, $baleNew->fresh()->reserved_order_id);

        // 3. New Invoice total is 350.00
        $this->assertEquals(350.00, $amendedInvoice->total_amount);
    }

    public function test_amendment_rejection_workflow(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 500.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 500.00],
            ],
        ], $this->companyA->id);

        $invoice = $sale['invoice'];

        $request = $this->amendmentService->requestAmendment(
            $invoice->id,
            [
                'reason'            => 'Unauthorized price reduction attempt',
                'requested_changes' => ['discount_amount' => 200.00],
            ],
            $this->salespersonUserA->id,
            $this->companyA->id
        );

        // Admin Rejects
        $rejectedReq = $this->amendmentService->rejectAmendment(
            $request->id,
            'Discount exceeds maximum salesperson discretion',
            $this->adminUserA->id,
            $this->companyA->id
        );

        $this->assertEquals('rejected', $rejectedReq->status);
        $this->assertEquals('Discount exceeds maximum salesperson discretion', $rejectedReq->rejection_reason);

        // Original Invoice remains 'finalized' and untouched
        $this->assertEquals('finalized', $invoice->fresh()->status);
        $this->assertEquals(500.00, $invoice->fresh()->total_amount);

        // Bale remains reserved
        $this->assertEquals('reserved', $bale->fresh()->status);

        // Audit Log
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.amendment_rejected']);
    }

    public function test_unauthorized_salesperson_cannot_approve_amendment(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 300.00],
            ],
        ], $this->companyA->id);

        $invoice = $sale['invoice'];

        $request = $this->amendmentService->requestAmendment(
            $invoice->id,
            [
                'reason'            => 'Price check',
                'requested_changes' => ['discount_amount' => 20.00],
            ],
            $this->salespersonUserA->id,
            $this->companyA->id
        );

        $salespersonToken = $this->salespersonUserA->createToken('sales_token')->plainTextToken;

        // Salesperson attempts to call approve endpoint -> MUST BE 403 FORBIDDEN
        $response = $this->withHeader('Authorization', 'Bearer ' . $salespersonToken)
            ->postJson("/api/invoices/amendment/{$request->id}/approve");

        $response->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertEquals('pending', $request->fresh()->status);
    }

    public function test_multi_company_isolation_on_amendments(): void
    {
        $containerB = Container::create(['supplier_name' => 'Supplier B', 'company_id' => $this->companyB->id]);
        $baleB = $this->baleService->createBale([
            'container_id'     => $containerB->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 400.00,
        ], $this->companyB->id);

        $saleB = $this->saleService->createSale([
            'buyer_id' => $this->buyerB->id,
            'items'    => [
                ['bale_ids' => [$baleB->id], 'unit_price' => 400.00],
            ],
        ], $this->companyB->id);

        $invoiceB = $saleB['invoice'];

        // Staff from Company A attempts to request amendment on Company B invoice -> MUST FAIL
        $this->expectException(ValidationException::class);
        $this->amendmentService->requestAmendment(
            $invoiceB->id,
            [
                'reason'            => 'Cross-company attempt',
                'requested_changes' => ['discount_amount' => 50.00],
            ],
            $this->salespersonUserA->id,
            $this->companyA->id
        );
    }
}

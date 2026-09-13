<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleAndPermissionSeeder;
use App\Models\Company;
use App\Models\Buyer;
use App\Models\Container;
use App\Models\Bale;
use App\Models\Item_category;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\InvoiceAmendmentRequest;
use App\Models\Payment;
use App\Models\BankDeposit;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\BaleService;
use App\Services\SaleService;
use App\Services\WhatsApp\WhatsAppRouterService;

class Phase16RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected User $superAdmin;
    protected User $adminUserA;
    protected User $managerUserA;
    protected User $cashierUserA;
    protected User $staffUserA;
    protected User $salespersonUserA;
    protected User $unassignedUserA;

    protected Buyer $buyerA;
    protected Buyer $buyerB;
    protected Item_category $categoryJeans;
    protected BaleService $baleService;
    protected SaleService $saleService;
    protected WhatsAppRouterService $routerService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Seed Canonical Roles & Permissions
        $this->seed(RoleAndPermissionSeeder::class);

        $this->baleService = app(BaleService::class);
        $this->saleService = app(SaleService::class);
        $this->routerService = app(WhatsAppRouterService::class);

        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi', 'currency' => 'GHS']);

        // Users
        $this->superAdmin = User::create([
            'name'       => 'Super Administrator',
            'email'      => 'superadmin@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->superAdmin->assignRole('Super Admin');

        $this->adminUserA = User::create([
            'name'       => 'Accra Admin',
            'email'      => 'admin@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->adminUserA->assignRole('Admin');

        $this->managerUserA = User::create([
            'name'       => 'Accra Manager',
            'email'      => 'manager@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->managerUserA->assignRole('Manager');

        $this->cashierUserA = User::create([
            'name'       => 'Accra Cashier',
            'email'      => 'cashier@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->cashierUserA->assignRole('Cashier');

        $this->staffUserA = User::create([
            'name'       => 'Accra Warehouse Staff',
            'email'      => 'staff@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->staffUserA->assignRole('Staff');

        $this->salespersonUserA = User::create([
            'name'       => 'Accra Salesperson',
            'email'      => 'sales@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->salespersonUserA->assignRole('Salesperson');

        $this->unassignedUserA = User::create([
            'name'       => 'Unassigned User',
            'email'      => 'unassigned@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);

        $this->buyerA = Buyer::create([
            'name'            => 'Buyer Accra',
            'whatsapp_number' => '+233241112233',
            'company_id'      => $this->companyA->id,
        ]);

        $this->buyerB = Buyer::create([
            'name'            => 'Buyer Kumasi',
            'whatsapp_number' => '+233242223344',
            'company_id'      => $this->companyB->id,
        ]);

        $this->categoryJeans = Item_category::create(['category_name' => 'Denim Jeans Grade A']);
    }

    public function test_staff_cannot_record_cash_or_bank_deposit(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 350.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 350.00]],
        ], $this->companyA->id);
        $invoice = $sale['invoice'];

        $staffToken = $this->staffUserA->createToken('staff_token')->plainTextToken;

        // 1. Staff attempts cash recording -> REJECTED (403)
        $staffCashRes = $this->withHeaders(['Authorization' => 'Bearer ' . $staffToken])
            ->postJson('/api/payment/cash', [
                'company_id' => $this->companyA->id,
                'invoice_id' => $invoice->id,
                'amount'     => 350.00,
            ]);
        $staffCashRes->assertStatus(403);

        // 2. Staff attempts bank deposit -> REJECTED (403)
        $staffDepRes = $this->withHeaders(['Authorization' => 'Bearer ' . $staffToken])
            ->postJson('/api/bank-deposits/create', [
                'company_id'       => $this->companyA->id,
                'amount'           => 100.00,
                'bank_name'        => 'GCB Bank',
                'reference_number' => 'DEP-STF-001',
            ]);
        $staffDepRes->assertStatus(403);
    }

    public function test_cashier_can_record_cash_and_bank_deposit(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 350.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 350.00]],
        ], $this->companyA->id);
        $invoice = $sale['invoice'];

        $cashierToken = $this->cashierUserA->createToken('cashier_token')->plainTextToken;

        // 1. Cashier records cash -> SUCCESS (200)
        $cashierCashRes = $this->withHeaders(['Authorization' => 'Bearer ' . $cashierToken])
            ->postJson('/api/payment/cash', [
                'company_id' => $this->companyA->id,
                'invoice_id' => $invoice->id,
                'amount'     => 350.00,
            ]);
        $cashierCashRes->assertStatus(200)->assertJson(['success' => true, 'payment_status' => 'paid']);

        // 2. Cashier records bank deposit -> SUCCESS (201)
        $cashierDepRes = $this->withHeaders(['Authorization' => 'Bearer ' . $cashierToken])
            ->postJson('/api/bank-deposits/create', [
                'company_id'       => $this->companyA->id,
                'amount'           => 350.00,
                'bank_name'        => 'GCB Bank',
                'reference_number' => 'DEP-CSH-001',
            ]);
        $cashierDepRes->assertStatus(201)->assertJson(['success' => true]);
    }

    public function test_staff_cannot_approve_amendments_or_generate_eod(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 200.00]],
        ], $this->companyA->id);
        $invoice = $sale['invoice'];

        $amendmentRequest = InvoiceAmendmentRequest::create([
            'invoice_id'        => $invoice->id,
            'requested_by'      => $this->staffUserA->id,
            'reason'            => 'Price discount request',
            'requested_changes' => ['discount_amount' => 20.00],
            'status'            => 'pending',
        ]);

        $staffToken = $this->staffUserA->createToken('staff_token')->plainTextToken;

        // 1. Staff attempts EOD generation -> REJECTED (403)
        $staffEodRes = $this->withHeaders(['Authorization' => 'Bearer ' . $staffToken])
            ->postJson('/api/reconciliation/eod/generate', [
                'company_id' => $this->companyA->id,
                'date'       => now()->toDateString(),
            ]);
        $staffEodRes->assertStatus(403);

        // 2. Staff attempts amendment approval -> REJECTED (403)
        $staffApproveRes = $this->withHeaders(['Authorization' => 'Bearer ' . $staffToken])
            ->postJson("/api/invoices/amendment/{$amendmentRequest->id}/approve", [
                'company_id' => $this->companyA->id,
            ]);
        $staffApproveRes->assertStatus(403);
    }

    public function test_admin_and_manager_can_approve_amendments_and_generate_eod(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 200.00]],
        ], $this->companyA->id);
        $invoice = $sale['invoice'];

        $amendmentRequest = InvoiceAmendmentRequest::create([
            'invoice_id'        => $invoice->id,
            'requested_by'      => $this->staffUserA->id,
            'reason'            => 'Price discount approved by manager',
            'requested_changes' => ['discount_amount' => 20.00],
            'status'            => 'pending',
        ]);

        $managerToken = $this->managerUserA->createToken('manager_token')->plainTextToken;
        $adminToken = $this->adminUserA->createToken('admin_token')->plainTextToken;

        // 1. Manager approves amendment -> SUCCESS (200)
        $mgrApproveRes = $this->withHeaders(['Authorization' => 'Bearer ' . $managerToken])
            ->postJson("/api/invoices/amendment/{$amendmentRequest->id}/approve", [
                'company_id' => $this->companyA->id,
            ]);
        $mgrApproveRes->assertStatus(200)->assertJson(['success' => true]);

        // 2. Admin generates EOD reconciliation -> SUCCESS (200)
        $adminEodRes = $this->withHeaders(['Authorization' => 'Bearer ' . $adminToken])
            ->postJson('/api/reconciliation/eod/generate', [
                'company_id' => $this->companyA->id,
                'date'       => now()->toDateString(),
            ]);
        $adminEodRes->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_multi_company_isolation_cannot_be_bypassed_by_admin_role(): void
    {
        // Admin of Company A attempts to record payment on Company B invoice
        $containerB = Container::create(['supplier_name' => 'Supplier B', 'company_id' => $this->companyB->id]);
        $baleB = $this->baleService->createBale([
            'container_id'     => $containerB->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 400.00,
        ], $this->companyB->id);

        $saleB = $this->saleService->createSale([
            'buyer_id' => $this->buyerB->id,
            'items'    => [['bale_ids' => [$baleB->id], 'unit_price' => 400.00]],
        ], $this->companyB->id);
        $invoiceB = $saleB['invoice'];

        $adminTokenA = $this->adminUserA->createToken('admin_token_a')->plainTextToken;

        // Admin of Company A attempts to pay Company B invoice using Company A context -> MUST FAIL
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $adminTokenA])
            ->postJson('/api/payment/cash', [
                'company_id' => $this->companyA->id, // Wrong company context!
                'invoice_id' => $invoiceB->id,
                'amount'     => 400.00,
            ]);

        // Validation exception caught by Laravel JSON handler (422)
        $response->assertStatus(422);
        $this->assertEquals('unpaid', $invoiceB->fresh()->payment_status);
    }

    public function test_authorization_denial_writes_audit_log_entry(): void
    {
        $salesToken = $this->salespersonUserA->createToken('sales_token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $salesToken])
            ->postJson('/api/reconciliation/eod/generate', [
                'company_id' => $this->companyA->id,
                'date'       => now()->toDateString(),
            ]);

        $response->assertStatus(403);

        // Verify audit log entry recorded
        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'auth.access_denied',
            'user_id'    => $this->salespersonUserA->id,
            'company_id' => $this->companyA->id,
        ]);
    }

    public function test_whatsapp_role_handlers_prevent_privilege_escalation(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 250.00,
        ], $this->companyA->id);

        \App\Models\Customer::create([
            'wa_id'             => '+233241112233',
            'whatsapp_number'   => '+233241112233',
            'name'              => 'Buyer Accra',
            'onboarding_status' => 6,
        ]);

        // 1. Buyer sends admin command "eod" -> Treated as standard conversational fallback
        $buyerEodRes = $this->routerService->route('buyer', '+233241112233', 'eod', $this->companyA->id);
        $this->assertNotEquals('admin_eod', $buyerEodRes['action']);
        $this->assertStringContainsString('How can I help you', $buyerEodRes['reply']);

        // 2. Buyer sends staff command "release PKP-1234" -> Treated as standard conversational fallback
        $buyerReleaseRes = $this->routerService->route('buyer', '+233241112233', 'release PKP-1234', $this->companyA->id);
        $this->assertNotEquals('pickup_released', $buyerReleaseRes['action']);

        // 3. Staff sends cashier command "deposit 100 GCB ref" -> Staff handler does not execute deposit
        $staffDepRes = $this->routerService->route('staff', '+233240001111', 'deposit 100 GCB ref', $this->companyA->id, $this->staffUserA->id);
        $this->assertNotEquals('deposit_recorded', $staffDepRes['action']);
    }
}

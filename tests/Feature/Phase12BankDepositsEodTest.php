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
use App\Models\Payment;
use App\Models\BankDeposit;
use App\Models\Expense;
use App\Models\DailyReconciliation;
use App\Models\DailySnapshot;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\BaleService;
use App\Services\SaleService;
use App\Services\PaymentService;
use App\Services\PickupService;
use App\Services\ExpenseService;
use App\Services\BankDepositService;
use App\Services\EodReconciliationService;
use Spatie\Permission\Models\Role;

class Phase12BankDepositsEodTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected Buyer $buyerA;
    protected Buyer $buyerB;
    protected User $adminUserA;
    protected User $cashierUserA;
    protected User $salespersonUserA;
    protected User $adminUserB;
    protected Item_category $categoryDresses;

    protected BaleService $baleService;
    protected SaleService $saleService;
    protected PaymentService $paymentService;
    protected PickupService $pickupService;
    protected ExpenseService $expenseService;
    protected BankDepositService $depositService;
    protected EodReconciliationService $eodService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baleService = app(BaleService::class);
        $this->saleService = app(SaleService::class);
        $this->paymentService = app(PaymentService::class);
        $this->pickupService = app(PickupService::class);
        $this->expenseService = app(ExpenseService::class);
        $this->depositService = app(BankDepositService::class);
        $this->eodService = app(EodReconciliationService::class);

        // Companies
        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi', 'currency' => 'GHS']);

        // Roles
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $cashierRole = Role::firstOrCreate(['name' => 'Cashier']);
        $salesRole = Role::firstOrCreate(['name' => 'Salesperson']);

        // Users
        $this->adminUserA = User::create([
            'name'       => 'Accra Admin',
            'email'      => 'admin_accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->adminUserA->assignRole($adminRole);

        $this->cashierUserA = User::create([
            'name'       => 'Accra Cashier',
            'email'      => 'cashier_accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->cashierUserA->assignRole($cashierRole);

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
            'business_name'   => 'Mensah Wholesale',
            'whatsapp_number' => '+233241112233',
            'company_id'      => $this->companyA->id,
        ]);

        $this->buyerB = Buyer::create([
            'name'            => 'Abena Darko',
            'business_name'   => 'Darko Retail',
            'whatsapp_number' => '+233242223344',
            'company_id'      => $this->companyB->id,
        ]);

        // Category
        $this->categoryDresses = Item_category::create([
            'category_name' => 'Summer Dresses Grade A',
            'category_type' => 'Garments',
        ]);
    }

    public function test_bank_deposit_recording_validation_and_duplicate_prevention(): void
    {
        // 1. Valid Bank Deposit
        $deposit1 = $this->depositService->recordDeposit([
            'company_id'       => $this->companyA->id,
            'amount'           => 1200.00,
            'bank_name'        => 'GCB Bank',
            'account_number'   => '1029384756',
            'reference_number' => 'DEP-REF-1001',
            'notes'            => 'Cash from counter deposit',
        ], $this->cashierUserA->id, $this->companyA->id);

        $this->assertEquals(1200.00, $deposit1->amount);
        $this->assertEquals('GCB Bank', $deposit1->bank_name);
        $this->assertStringStartsWith('DEP-', $deposit1->deposit_number);

        // 2. Reject Duplicate Reference Number
        $this->expectException(ValidationException::class);
        $this->depositService->recordDeposit([
            'company_id'       => $this->companyA->id,
            'amount'           => 500.00,
            'bank_name'        => 'GCB Bank',
            'reference_number' => 'DEP-REF-1001',
        ], $this->cashierUserA->id, $this->companyA->id);
    }

    public function test_bank_deposit_zero_or_negative_amount_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->depositService->recordDeposit([
            'company_id' => $this->companyA->id,
            'amount'     => 0.00,
            'bank_name'  => 'GCB Bank',
        ], $this->cashierUserA->id, $this->companyA->id);
    }

    public function test_end_to_end_eod_financial_and_stock_reconciliation(): void
    {
        $today = now()->toDateString();

        // 1. Create Bales & Sales (Total 1000.00 across 2 sales)
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale1 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 200.00,
            'selling_price'    => 600.00,
        ], $this->companyA->id);

        $bale2 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 150.00,
            'selling_price'    => 400.00,
        ], $this->companyA->id);

        // Sale 1: 600.00
        $sale1 = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale1->id], 'unit_price' => 600.00],
            ],
        ], $this->companyA->id);

        // Sale 2: 400.00
        $sale2 = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale2->id], 'unit_price' => 400.00],
            ],
        ], $this->companyA->id);

        // 2. Pay Sale 1 in full with cash (600.00)
        $this->paymentService->recordCashPayment([
            'invoice_id' => $sale1['invoice']->id,
            'amount'     => 600.00,
        ], $this->cashierUserA->id, $this->companyA->id);

        // Sale 2 remains unpaid (400.00 pending)

        // 3. Dispatch & Release Sale 1 goods
        $this->pickupService->releaseOrderBales($sale1['order']->pickup_code, $this->cashierUserA->id, $this->companyA->id);

        // 4. Record Bank Deposit (400.00)
        $this->depositService->recordDeposit([
            'company_id'       => $this->companyA->id,
            'amount'           => 400.00,
            'bank_name'        => 'GCB Bank',
            'reference_number' => 'EOD-DEP-001',
        ], $this->cashierUserA->id, $this->companyA->id);

        // 5. Record Expense (100.00)
        $this->expenseService->recordExpense([
            'company_id'  => $this->companyA->id,
            'amount'      => 100.00,
            'category'    => 'offloading',
            'description' => 'Daily labour payment',
        ], $this->cashierUserA->id, $this->companyA->id);

        // 6. Generate EOD Reconciliation Report
        $report = $this->eodService->generateDailyReconciliation($today, $this->adminUserA->id, $this->companyA->id, true);

        // Assert Exact Financial Aggregates
        $this->assertEquals(1000.00, $report['total_sales_amount']); // 600 + 400
        $this->assertEquals(600.00, $report['total_cash_collected']); // 600
        $this->assertEquals(400.00, $report['total_bank_deposits']);  // 400
        $this->assertEquals(100.00, $report['total_expenses']);       // 100
        $this->assertEquals(100.00, $report['net_cash_balance']);     // 600 - 400 - 100 = 100
        $this->assertEquals(1, $report['pending_invoices_count']);    // Sale 2
        $this->assertEquals(400.00, $report['pending_invoices_amount']);

        // Assert Stored Database Records
        $this->assertDatabaseHas('daily_reconciliations', [
            'company_id' => $this->companyA->id,
            'status'     => 'reconciled',
        ]);

        $this->assertDatabaseHas('daily_snapshots', [
            'company_id' => $this->companyA->id,
        ]);

        // Assert Audit Log
        $this->assertDatabaseHas('audit_logs', ['action' => 'eod.reconciliation_generated']);
    }

    public function test_unauthorized_salesperson_cannot_generate_eod(): void
    {
        $salespersonToken = $this->salespersonUserA->createToken('sales_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $salespersonToken)
            ->postJson('/api/reconciliation/eod/generate', [
                'date'       => now()->toDateString(),
                'company_id' => $this->companyA->id,
            ]);

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_api_bank_deposits_and_eod_endpoints(): void
    {
        $adminToken = $this->adminUserA->createToken('admin_token')->plainTextToken;

        // 1. API: Create Bank Deposit
        $depositRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/bank-deposits/create', [
                'company_id'       => $this->companyA->id,
                'amount'           => 300.00,
                'bank_name'        => 'Ecobank',
                'reference_number' => 'ECO-9988',
            ]);

        $depositRes->assertStatus(201)
            ->assertJson(['success' => true]);

        // 2. API: Bank Deposit Summary
        $summaryRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/bank-deposits/summary');

        $summaryRes->assertStatus(200)
            ->assertJson(['success' => true]);

        // 3. API: Generate EOD
        $eodRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/reconciliation/eod/generate', [
                'date'       => now()->toDateString(),
                'company_id' => $this->companyA->id,
            ]);

        $eodRes->assertStatus(200)
            ->assertJson(['success' => true]);

        // 4. API: Show EOD
        $showRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/reconciliation/eod/show?date=' . now()->toDateString());

        $showRes->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}

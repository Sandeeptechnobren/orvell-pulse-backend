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
use App\Models\StockManagement;
use App\Models\User;
use App\Models\Payment;
use App\Models\BankDeposit;
use App\Models\Expense;
use App\Services\BaleService;
use App\Services\SaleService;
use App\Services\PaymentService;
use App\Services\PickupService;
use App\Services\ExpenseService;
use App\Services\BankDepositService;

class Phase18DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected User $adminUserA;
    protected User $adminUserB;
    protected Item_category $categoryJeans;
    protected Item_category $categoryShirts;

    protected BaleService $baleService;
    protected SaleService $saleService;
    protected PaymentService $paymentService;
    protected PickupService $pickupService;
    protected ExpenseService $expenseService;
    protected BankDepositService $depositService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->baleService = app(BaleService::class);
        $this->saleService = app(SaleService::class);
        $this->paymentService = app(PaymentService::class);
        $this->pickupService = app(PickupService::class);
        $this->expenseService = app(ExpenseService::class);
        $this->depositService = app(BankDepositService::class);

        $this->companyA = Company::create(['company_name' => 'Orvell Accra Wholesale', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi Wholesale', 'currency' => 'GHS']);

        $this->adminUserA = User::create([
            'name'       => 'Accra Admin',
            'email'      => 'admin.accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->adminUserA->assignRole('Admin');

        $this->adminUserB = User::create([
            'name'       => 'Kumasi Admin',
            'email'      => 'admin.kumasi@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyB->id,
        ]);
        $this->adminUserB->assignRole('Admin');

        $this->categoryJeans = Item_category::create(['category_name' => 'Denim Jeans Grade A']);
        $this->categoryShirts = Item_category::create(['category_name' => 'Cotton Shirts Grade A']);
    }

    public function test_dashboard_overview_returns_live_kpis_for_company(): void
    {
        // 1. Setup Inventory
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id, 'status' => 'in_stock']);
        $bale1 = $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 300.00], $this->companyA->id);
        $bale2 = $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 300.00], $this->companyA->id);

        $buyer = Buyer::create(['name' => 'Kofi', 'company_id' => $this->companyA->id]);

        // 2. Setup Sale & Cash Payment
        $sale = $this->saleService->createSale([
            'buyer_id' => $buyer->id,
            'items'    => [['bale_ids' => [$bale1->id], 'unit_price' => 300.00]],
        ], $this->companyA->id);

        $this->paymentService->recordCashPayment([
            'invoice_id' => $sale['invoice']->id,
            'amount'     => 300.00,
        ], $this->adminUserA->id, $this->companyA->id);

        // 3. Setup Expense (50.00) & Bank Deposit (150.00)
        $this->expenseService->recordExpense([
            'amount'   => 50.00,
            'category' => 'Transport',
        ], $this->adminUserA->id, $this->companyA->id);

        $this->depositService->recordDeposit([
            'amount'           => 150.00,
            'bank_name'        => 'GCB Bank',
            'reference_number' => 'DEP-DASH-01',
        ], $this->adminUserA->id, $this->companyA->id);

        // 4. Request Dashboard Overview
        $token = $this->adminUserA->createToken('dash_token')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/dashboard/overview');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'company' => [
                        'id'       => $this->companyA->id,
                        'currency' => 'GHS',
                    ],
                    'financials' => [
                        'today_sales'          => 300.00,
                        'today_cash_collected' => 300.00,
                        'today_bank_deposits'  => 150.00,
                        'today_expenses'       => 50.00,
                        'net_cash_in_till'     => 100.00, // 300 - 150 - 50 = 100
                    ],
                    'inventory' => [
                        'total_bales'       => 2,
                        'available_bales'   => 1,
                        'reserved_bales'    => 1,
                        'released_bales'    => 0,
                        'active_containers' => 1,
                    ],
                    'orders' => [
                        'today_orders_count'    => 1,
                        'pending_pickups_count' => 1,
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.low_stock_alerts'));
        $this->assertNotEmpty($response->json('data.recent_activity'));
    }

    public function test_dashboard_overview_enforces_strict_multi_company_isolation(): void
    {
        // Company B records 1000.00 in sales
        $containerB = Container::create(['supplier_name' => 'Kumasi Supplier', 'company_id' => $this->companyB->id, 'status' => 'in_stock']);
        $baleB = $this->baleService->createBale(['container_id' => $containerB->id, 'item_category_id' => $this->categoryShirts->id, 'selling_price' => 1000.00], $this->companyB->id);
        $buyerB = Buyer::create(['name' => 'Kwame', 'company_id' => $this->companyB->id]);

        $saleB = $this->saleService->createSale([
            'buyer_id' => $buyerB->id,
            'items'    => [['bale_ids' => [$baleB->id], 'unit_price' => 1000.00]],
        ], $this->companyB->id);

        $this->paymentService->recordCashPayment([
            'invoice_id' => $saleB['invoice']->id,
            'amount'     => 1000.00,
        ], $this->adminUserB->id, $this->companyB->id);

        // Admin of Company A queries dashboard -> Must see 0.00 for Company A
        $tokenA = $this->adminUserA->createToken('admin_a')->plainTextToken;
        $responseA = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->getJson('/api/dashboard/overview');

        $responseA->assertStatus(200);
        $this->assertEquals(0.00, $responseA->json('data.financials.today_sales'));
        $this->assertEquals(0.00, $responseA->json('data.financials.today_cash_collected'));
        $this->assertEquals(0, $responseA->json('data.inventory.total_bales'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/dashboard/overview');
        $response->assertStatus(401);
    }
}

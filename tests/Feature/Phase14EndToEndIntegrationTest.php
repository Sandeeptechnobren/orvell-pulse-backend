<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Company;
use App\Models\Buyer;
use App\Models\Container;
use App\Models\Bale;
use App\Models\Item_category;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Release;
use App\Models\Expense;
use App\Models\BankDeposit;
use App\Models\DailyReconciliation;
use App\Models\DailySnapshot;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\InventoryTransaction;
use App\Services\BaleService;
use App\Services\ContainerService;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Services\PaymentService;
use App\Services\PickupService;
use App\Services\ExpenseService;
use App\Services\BankDepositService;
use App\Services\EodReconciliationService;
use App\Services\WhatsAppAgentService;
use Spatie\Permission\Models\Role;

class Phase14EndToEndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $adminUser;
    protected User $cashierUser;
    protected User $warehouseStaff;
    protected Item_category $categoryMenJeans;
    protected Item_category $categoryWomenDresses;

    protected ContainerService $containerService;
    protected BaleService $baleService;
    protected InventoryService $inventoryService;
    protected SaleService $saleService;
    protected PaymentService $paymentService;
    protected PickupService $pickupService;
    protected ExpenseService $expenseService;
    protected BankDepositService $depositService;
    protected EodReconciliationService $eodService;
    protected WhatsAppAgentService $whatsappBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->containerService = app(ContainerService::class);
        $this->baleService = app(BaleService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->saleService = app(SaleService::class);
        $this->paymentService = app(PaymentService::class);
        $this->pickupService = app(PickupService::class);
        $this->expenseService = app(ExpenseService::class);
        $this->depositService = app(BankDepositService::class);
        $this->eodService = app(EodReconciliationService::class);
        $this->whatsappBot = app(WhatsAppAgentService::class);

        // 1. Company Setup
        $this->company = Company::create([
            'company_name' => 'Orvell Pulse Wholesale Ltd',
            'currency'     => 'GHS',
            'country'      => 'Ghana',
        ]);

        // 2. Roles & Users
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $cashierRole = Role::firstOrCreate(['name' => 'Cashier']);
        $staffRole = Role::firstOrCreate(['name' => 'Staff']);

        $this->adminUser = User::create([
            'name'       => 'Managing Director',
            'email'      => 'admin@orvellpulse.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->company->id,
        ]);
        $this->adminUser->assignRole($adminRole);

        $this->cashierUser = User::create([
            'name'       => 'Head Cashier',
            'email'      => 'cashier@orvellpulse.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->company->id,
        ]);
        $this->cashierUser->assignRole($cashierRole);

        $this->warehouseStaff = User::create([
            'name'       => 'Warehouse Dispatcher',
            'email'      => 'warehouse@orvellpulse.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->company->id,
        ]);
        $this->warehouseStaff->assignRole($staffRole);

        // 3. Categories
        $this->categoryMenJeans = Item_category::create([
            'category_name' => 'Men Jeans Grade A',
            'category_type' => 'Garments',
        ]);

        $this->categoryWomenDresses = Item_category::create([
            'category_name' => 'Women Silk Dresses',
            'category_type' => 'Garments',
        ]);
    }

    public function test_complete_orvell_pulse_wholesale_lifecycle(): void
    {
        $today = now()->toDateString();

        // -------------------------------------------------------------
        // STEP 1: Container Arrival & Unpacking (Physical Inventory Inbound)
        // -------------------------------------------------------------
        $container = $this->containerService->createContainer([
            'supplier_name'    => 'EuroCotton S.A.',
            'container_number' => 'MSCU-9081234',
            'company_id'       => $this->company->id,
        ], $this->company->id);

        $this->containerService->processContainerArrival($container->id, [
            [
                'item_category_id' => $this->categoryMenJeans->id,
                'quantity'         => 5,
                'weight_kg'        => 45.0,
                'cost_price'       => 200.00,
                'selling_price'    => 500.00,
            ],
            [
                'item_category_id' => $this->categoryWomenDresses->id,
                'quantity'         => 3,
                'weight_kg'        => 40.0,
                'cost_price'       => 180.00,
                'selling_price'    => 400.00,
            ],
        ], $this->adminUser->id, $this->company->id);

        $this->assertEquals(8, Bale::where('company_id', $this->company->id)->where('status', 'available')->count());

        // -------------------------------------------------------------
        // STEP 2: Buyer Onboarding via WhatsApp
        // -------------------------------------------------------------
        $buyerPhone = '+233249001122';
        $this->whatsappBot->handle('customer', $buyerPhone, 'Kwame Mensah', $this->company->id);
        $this->whatsappBot->handle('customer', $buyerPhone, 'kwame@mensahwholesalers.com', $this->company->id);
        $this->whatsappBot->handle('customer', $buyerPhone, 'Accra Central', $this->company->id);

        $buyer = Buyer::where('whatsapp_number', $buyerPhone)->first();
        $this->assertNotNull($buyer);
        $this->assertEquals('Kwame Mensah', $buyer->name);

        // -------------------------------------------------------------
        // STEP 3: Sale Creation & Atomic Physical Bale Reservation
        // -------------------------------------------------------------
        $availableJeans = Bale::where('item_category_id', $this->categoryMenJeans->id)
            ->where('company_id', $this->company->id)
            ->where('status', 'available')
            ->take(2)
            ->get();

        $saleResult = $this->saleService->createSale([
            'buyer_id' => $buyer->id,
            'items'    => [
                [
                    'bale_ids'          => $availableJeans->pluck('id')->toArray(),
                    'item_category_id'  => $this->categoryMenJeans->id,
                    'item_description'  => $this->categoryMenJeans->category_name,
                    'unit_price'        => 500.00,
                ],
            ],
        ], $this->company->id);

        $order = $saleResult['order'];
        $invoice = $saleResult['invoice'];

        $this->assertEquals(1000.00, $order->total_amount);
        $this->assertEquals('pending', $order->payment_status);
        $this->assertEquals('pending', $order->pickup_status);
        $this->assertNotNull($order->pickup_code);
        $this->assertEquals('finalized', $invoice->status);

        // Assert bales are reserved (not released yet!)
        foreach ($availableJeans as $bale) {
            $this->assertEquals('reserved', $bale->fresh()->status);
        }

        // -------------------------------------------------------------
        // STEP 4: Cashier Payment Settlement
        // -------------------------------------------------------------
        $paymentResult = $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 1000.00,
            'notes'      => 'Full payment at cashier counter',
        ], $this->cashierUser->id, $this->company->id);

        $this->assertEquals(1000.00, $paymentResult['payment']->amount);
        $this->assertEquals('paid', $invoice->fresh()->payment_status);
        $this->assertEquals('paid', $order->fresh()->payment_status);

        // Assert bales are STILL reserved after payment
        foreach ($availableJeans as $bale) {
            $this->assertEquals('reserved', $bale->fresh()->status);
        }

        // -------------------------------------------------------------
        // STEP 5: Warehouse Pickup Presentation & Physical Release
        // -------------------------------------------------------------
        // Validate pickup code
        $validation = $this->pickupService->validatePickupCode($order->pickup_code, $this->company->id);
        $this->assertTrue($validation['valid']);
        $this->assertEquals(2, $validation['reserved_bales_count']);

        // Physical dispatch release
        $releaseResult = $this->pickupService->releaseOrderBales($order->pickup_code, $this->warehouseStaff->id, $this->company->id, 'Dispatched to customer pickup truck');

        $this->assertTrue($releaseResult['success']);
        $this->assertEquals('completed', $order->fresh()->pickup_status);
        $this->assertEquals('completed', $order->fresh()->status);

        // Assert bales are now physically RELEASED
        foreach ($availableJeans as $bale) {
            $this->assertEquals('released', $bale->fresh()->status);
            $this->assertNotNull($bale->fresh()->released_at);
        }

        // -------------------------------------------------------------
        // STEP 6: Operational Expense Recording
        // -------------------------------------------------------------
        $expense = $this->expenseService->recordExpense([
            'company_id'  => $this->company->id,
            'amount'      => 150.00,
            'category'    => 'offloading',
            'description' => 'Container offloading labour cost',
        ], $this->cashierUser->id, $this->company->id);

        $this->assertEquals(150.00, $expense->amount);

        // -------------------------------------------------------------
        // STEP 7: Cashier Bank Deposit
        // -------------------------------------------------------------
        $deposit = $this->depositService->recordDeposit([
            'company_id'       => $this->company->id,
            'amount'           => 700.00,
            'bank_name'        => 'GCB Bank Commercial',
            'account_number'   => '1099228833',
            'reference_number' => 'EOD-DEP-PULSE-001',
            'notes'            => 'Daily cash deposit into bank',
        ], $this->cashierUser->id, $this->company->id);

        $this->assertEquals(700.00, $deposit->amount);

        // -------------------------------------------------------------
        // STEP 8: End-of-Day (EOD) Reconciliation & Snapshot Generation
        // -------------------------------------------------------------
        $eodReport = $this->eodService->generateDailyReconciliation($today, $this->adminUser->id, $this->company->id, true);

        // Financial Verification:
        // Cash In: 1000.00
        // Bank Deposit: 700.00
        // Expenses: 150.00
        // Net Cash in Drawer: 1000 - 700 - 150 = 150.00
        $this->assertEquals(1000.00, $eodReport['total_sales_amount']);
        $this->assertEquals(1000.00, $eodReport['total_cash_collected']);
        $this->assertEquals(700.00, $eodReport['total_bank_deposits']);
        $this->assertEquals(150.00, $eodReport['total_expenses']);
        $this->assertEquals(150.00, $eodReport['net_cash_balance']);
        $this->assertEquals(0, $eodReport['pending_invoices_count']);
        $this->assertEquals(0.00, $eodReport['pending_invoices_amount']);

        // Physical Inventory Verification:
        // Initial Bales: 8 (5 jeans, 3 dresses)
        // Bales Sold & Released: 2
        // Remaining Active (available + reserved) Bales: 6
        $this->assertEquals(6, $eodReport['stock_movement']['closing_stock_units']);
        $this->assertEquals(0, $eodReport['stock_variance_units']); // Perfect balance

        // -------------------------------------------------------------
        // STEP 9: Audit Trail Integrity Check
        // -------------------------------------------------------------
        $this->assertDatabaseHas('audit_logs', ['action' => 'container.create']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'container.arrival_processed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.create']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.create']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cash_recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bale.pickup_completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank_deposit.recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'eod.reconciliation_generated']);
    }
}

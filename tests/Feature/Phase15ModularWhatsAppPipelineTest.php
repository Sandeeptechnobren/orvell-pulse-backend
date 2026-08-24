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
use App\Models\InvoiceAmendmentRequest;
use App\Models\User;
use App\Services\BaleService;
use App\Services\WhatsApp\WhatsAppRouterService;
use App\Services\WhatsApp\Handlers\BuyerHandler;
use App\Services\WhatsApp\Handlers\StaffHandler;
use App\Services\WhatsApp\Handlers\CashierHandler;
use App\Services\WhatsApp\Handlers\AdminHandler;

class Phase15ModularWhatsAppPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected User $staffUser;
    protected User $cashierUser;
    protected User $adminUser;
    protected Item_category $categoryJeans;
    protected BaleService $baleService;
    protected WhatsAppRouterService $routerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baleService = app(BaleService::class);
        $this->routerService = app(WhatsAppRouterService::class);

        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi', 'currency' => 'GHS']);

        $this->staffUser = User::create([
            'name'       => 'Warehouse Staff',
            'email'      => 'staff@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);

        $this->cashierUser = User::create([
            'name'       => 'Head Cashier',
            'email'      => 'cashier@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);

        $this->adminUser = User::create([
            'name'       => 'Managing Admin',
            'email'      => 'admin@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);

        $this->categoryJeans = Item_category::create([
            'category_name' => 'Denim Jeans Grade A',
            'category_type' => 'Garments',
        ]);
    }

    public function test_buyer_handler_order_and_pickup_tracking(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 400.00,
        ], $this->companyA->id);

        $phone = '+233241112233';

        // 1. Buyer places order
        $orderRes = $this->routerService->route('buyer', $phone, 'order Denim Jeans Grade A 1', $this->companyA->id);
        $this->assertEquals('order_created', $orderRes['action']);
        $this->assertStringContainsString('Order Created', $orderRes['reply']);
        $this->assertStringContainsString('Pickup Code', $orderRes['reply']);

        // Assert Bale is reserved
        $this->assertEquals('reserved', $bale->fresh()->status);

        // 2. Buyer tracks pickup status
        $trackRes = $this->routerService->route('buyer', $phone, 'pickup', $this->companyA->id);
        $this->assertEquals('order_status', $trackRes['action']);
        $this->assertStringContainsString('Pickup Status: *pending*', $trackRes['reply']);
    }

    public function test_staff_handler_pickup_release_and_expenses(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 450.00,
        ], $this->companyA->id);

        // Place order first
        $orderRes = $this->routerService->route('buyer', '+233249998877', 'order Denim Jeans Grade A 1', $this->companyA->id);
        $order = Order::latest()->first();
        $pickupCode = $order->pickup_code;

        // 1. Staff confirms pickup release via WhatsApp
        $releaseRes = $this->routerService->route('staff', '+233240001111', "release {$pickupCode}", $this->companyA->id, $this->staffUser->id);
        $this->assertEquals('pickup_released', $releaseRes['action']);
        $this->assertStringContainsString('Pickup Dispatch Confirmed', $releaseRes['reply']);

        // Assert Bale is physically released
        $this->assertEquals('released', $bale->fresh()->status);
        $this->assertEquals('completed', $order->fresh()->pickup_status);

        // 2. Staff records warehouse expense
        $expenseRes = $this->routerService->route('staff', '+233240001111', 'expense 75.00 offloading Van offloading fee', $this->companyA->id, $this->staffUser->id);
        $this->assertEquals('expense_recorded', $expenseRes['action']);
        $this->assertStringContainsString('Expense Recorded', $expenseRes['reply']);
        $this->assertDatabaseHas('expenses', ['amount' => 75.00, 'category' => 'offloading']);
    }

    public function test_cashier_handler_cash_receipt_and_till_summary(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 500.00,
        ], $this->companyA->id);

        // Place order
        $this->routerService->route('buyer', '+233245554433', 'order Denim Jeans Grade A 1', $this->companyA->id);
        $order = Order::latest()->first();

        // 1. Cashier records cash payment
        $cashRes = $this->routerService->route('cashier', '+233240002222', "cash {$order->order_no} 500.00", $this->companyA->id, $this->cashierUser->id);
        $this->assertEquals('cash_recorded', $cashRes['action']);
        $this->assertStringContainsString('Cash Payment Recorded', $cashRes['reply']);
        $this->assertEquals('paid', $order->fresh()->payment_status);

        // 2. Cashier records bank deposit
        $depRes = $this->routerService->route('cashier', '+233240002222', 'deposit 300.00 GCB_Bank DEP-REF-909', $this->companyA->id, $this->cashierUser->id);
        $this->assertEquals('deposit_recorded', $depRes['action']);
        $this->assertStringContainsString('Bank Deposit Recorded', $depRes['reply']);

        // 3. Cashier queries till summary
        $tillRes = $this->routerService->route('cashier', '+233240002222', 'till', $this->companyA->id, $this->cashierUser->id);
        $this->assertEquals('cash_till_summary', $tillRes['action']);
        $this->assertStringContainsString('Cashier Daily Till Summary', $tillRes['reply']);
        $this->assertStringContainsString('Total Cash Collected: GHS 500.00', $tillRes['reply']);
        $this->assertStringContainsString('Bank Deposits: GHS 300.00', $tillRes['reply']);
    }

    public function test_admin_handler_eod_and_amendment_approval(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale1 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $bale2 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        // 1. Admin checks stock
        $stockRes = $this->routerService->route('admin', '+233240003333', 'stock', $this->companyA->id, $this->adminUser->id);
        $this->assertEquals('admin_command', $stockRes['action']);
        $this->assertStringContainsString('Live Warehouse Stock Report', $stockRes['reply']);

        // 2. Admin generates EOD reconciliation
        $eodRes = $this->routerService->route('admin', '+233240003333', 'eod', $this->companyA->id, $this->adminUser->id);
        $this->assertEquals('admin_eod', $eodRes['action']);
        $this->assertStringContainsString('EOD Reconciliation Generated', $eodRes['reply']);

        // 3. Create Sale & Amendment Request
        $this->routerService->route('buyer', '+233248887766', 'order Denim Jeans Grade A 1', $this->companyA->id);
        $order = Order::latest()->first();
        $invoice = $order->invoice;

        $amendmentRequest = InvoiceAmendmentRequest::create([
            'invoice_id'        => $invoice->id,
            'requested_by'      => $this->staffUser->id,
            'reason'            => 'Customer requested bale swap',
            'requested_changes' => [
                'bale_ids'   => [$bale2->id],
                'unit_price' => 320.00,
            ],
            'status'            => 'pending',
        ]);

        // Admin approves amendment via WhatsApp
        $approveRes = $this->routerService->route('admin', '+233240003333', "approve amendment {$amendmentRequest->id}", $this->companyA->id, $this->adminUser->id);
        $this->assertEquals('amendment_approved', $approveRes['action']);
        $this->assertStringContainsString('Amendment Approved', $approveRes['reply']);
        $this->assertEquals('approved', $amendmentRequest->fresh()->status);
    }
}

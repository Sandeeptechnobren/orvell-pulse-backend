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
use App\Models\Release;
use App\Models\Expense;
use App\Models\User;
use App\Models\InventoryTransaction;
use App\Models\AuditLog;
use App\Services\BaleService;
use App\Services\SaleService;
use App\Services\PaymentService;
use App\Services\PickupService;
use App\Services\ExpenseService;

class Phase11PickupExpensesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected Buyer $buyerA;
    protected Buyer $buyerB;
    protected User $staffUserA;
    protected User $staffUserB;
    protected Item_category $categoryDresses;

    protected BaleService $baleService;
    protected SaleService $saleService;
    protected PaymentService $paymentService;
    protected PickupService $pickupService;
    protected ExpenseService $expenseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baleService = app(BaleService::class);
        $this->saleService = app(SaleService::class);
        $this->paymentService = app(PaymentService::class);
        $this->pickupService = app(PickupService::class);
        $this->expenseService = app(ExpenseService::class);

        // Companies
        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi', 'currency' => 'GHS']);

        // Users
        $this->staffUserA = User::create([
            'name'       => 'Accra Warehouse Staff',
            'email'      => 'staff_accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);

        $this->staffUserB = User::create([
            'name'       => 'Kumasi Warehouse Staff',
            'email'      => 'staff_kumasi@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyB->id,
        ]);

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

    public function test_complete_pickup_validation_and_physical_bale_release_flow(): void
    {
        // 1. Create Sale (2 Bales, total 600.00)
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale1 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $bale2 = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale1->id, $bale2->id], 'unit_price' => 300.00],
            ],
        ], $this->companyA->id);

        $order = $sale['order'];
        $pickupCode = $order->pickup_code;

        // Ensure bales are reserved
        $this->assertEquals('reserved', $bale1->fresh()->status);
        $this->assertEquals('reserved', $bale2->fresh()->status);
        $this->assertEquals('pending', $order->pickup_status);

        // 2. Validate Pickup Code
        $validation = $this->pickupService->validatePickupCode($pickupCode, $this->companyA->id);
        $this->assertTrue($validation['valid']);
        $this->assertEquals(2, $validation['reserved_bales_count']);

        // 3. Process Physical Release
        $releaseResult = $this->pickupService->releaseOrderBales($pickupCode, $this->staffUserA->id, $this->companyA->id, 'Dispatched to customer van');

        $this->assertTrue($releaseResult['success']);
        $this->assertCount(2, $releaseResult['released_bales']);

        // 4. Check Bales are now 'released'
        $this->assertEquals('released', $bale1->fresh()->status);
        $this->assertEquals('released', $bale2->fresh()->status);
        $this->assertNotNull($bale1->fresh()->released_at);

        // 5. Check Order status updated to 'completed'
        $this->assertEquals('completed', $order->fresh()->pickup_status);
        $this->assertEquals('completed', $order->fresh()->status);

        // 6. Check Release record created
        $this->assertDatabaseHas('releases', [
            'pickup_code' => $pickupCode,
            'order_id'    => $order->id,
            'status'      => 'completed',
        ]);

        // 7. Check Inventory Transaction ledger entries
        $this->assertDatabaseHas('inventory_transactions', [
            'bale_id'          => $bale1->id,
            'transaction_type' => 'pickup_release',
        ]);

        // 8. Check Audit Log
        $this->assertDatabaseHas('audit_logs', ['action' => 'bale.pickup_completed']);
    }

    public function test_duplicate_pickup_attempt_is_rejected(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 400.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 400.00],
            ],
        ], $this->companyA->id);

        $pickupCode = $sale['order']->pickup_code;

        // Release 1 -> Succeeds
        $this->pickupService->releaseOrderBales($pickupCode, $this->staffUserA->id, $this->companyA->id);

        // Release 2 with same code -> MUST FAIL with ValidationException
        $this->expectException(ValidationException::class);
        $this->pickupService->releaseOrderBales($pickupCode, $this->staffUserA->id, $this->companyA->id);
    }

    public function test_multi_company_isolation_on_pickup_code(): void
    {
        $containerB = Container::create(['supplier_name' => 'Supplier B', 'company_id' => $this->companyB->id]);
        $baleB = $this->baleService->createBale([
            'container_id'     => $containerB->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 500.00,
        ], $this->companyB->id);

        $saleB = $this->saleService->createSale([
            'buyer_id' => $this->buyerB->id,
            'items'    => [
                ['bale_ids' => [$baleB->id], 'unit_price' => 500.00],
            ],
        ], $this->companyB->id);

        $pickupCodeB = $saleB['order']->pickup_code;

        // Staff from Company A attempts to validate or release Company B pickup code -> MUST FAIL
        $this->expectException(ValidationException::class);
        $this->pickupService->validatePickupCode($pickupCodeB, $this->companyA->id);
    }

    public function test_expense_recording_and_summary_aggregation(): void
    {
        // 1. Record Valid Expenses
        $expense1 = $this->expenseService->recordExpense([
            'company_id'  => $this->companyA->id,
            'amount'      => 150.00,
            'category'    => 'offloading',
            'description' => 'Container offloading labour cost',
        ], $this->staffUserA->id, $this->companyA->id);

        $this->assertEquals(150.00, $expense1->amount);
        $this->assertEquals('offloading', $expense1->category);
        $this->assertStringStartsWith('EXP-', $expense1->expense_number);

        $expense2 = $this->expenseService->recordExpense([
            'company_id'  => $this->companyA->id,
            'amount'      => 50.00,
            'category'    => 'utilities',
            'description' => 'Warehouse generator fuel',
        ], $this->staffUserA->id, $this->companyA->id);

        // 2. Assert Expense Summary
        $summary = $this->expenseService->getExpenseSummary(null, null, $this->companyA->id);
        $this->assertEquals(200.00, $summary['total_expense_amount']);
        $this->assertArrayHasKey('offloading', $summary['categories']);
        $this->assertArrayHasKey('utilities', $summary['categories']);

        // 3. Assert Audit Log
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.recorded']);
    }

    public function test_expense_zero_or_negative_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expenseService->recordExpense([
            'company_id' => $this->companyA->id,
            'amount'     => 0.00,
            'category'   => 'repairs',
        ], $this->staffUserA->id, $this->companyA->id);
    }

    public function test_api_pickup_and_expense_endpoints(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 250.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 250.00],
            ],
        ], $this->companyA->id);

        $order = $sale['order'];
        $pickupCode = $order->pickup_code;

        $token = $this->staffUserA->createToken('staff_token')->plainTextToken;

        // 1. API: Validate Pickup Code
        $validateRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/pickup/validate', [
                'company_id'  => $this->companyA->id,
                'pickup_code' => $pickupCode,
            ]);

        $validateRes->assertStatus(200)
            ->assertJson(['success' => true]);

        // 2. API: Release Bales
        $releaseRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/pickup/release', [
                'company_id'  => $this->companyA->id,
                'pickup_code' => $pickupCode,
                'notes'       => 'API dispatch',
            ]);

        $releaseRes->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertEquals('completed', $order->fresh()->pickup_status);

        // 3. API: Create Expense
        $expenseRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/expenses/create', [
                'company_id'  => $this->companyA->id,
                'amount'      => 80.00,
                'category'    => 'transport',
                'description' => 'Delivery truck loading',
            ]);

        $expenseRes->assertStatus(201)
            ->assertJson(['success' => true]);

        // 4. API: Get Expense Summary
        $summaryRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/expenses/summary');

        $summaryRes->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}

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
use App\Models\Order;
use App\Models\Invoice;
use App\Models\InvoiceAmendmentRequest;
use App\Models\Payment;
use App\Models\BankDeposit;
use App\Models\Expense;
use App\Models\DailySnapshot;
use App\Models\EmailOtp;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\BaleService;
use App\Services\SaleService;
use App\Services\PaymentService;
use App\Services\PickupService;
use App\Services\InvoiceAmendmentService;
use App\Services\ExpenseService;
use App\Services\BankDepositService;
use App\Services\EodReconciliationService;
use App\Services\ReconciliationService;
use App\Services\OtpService;
use App\Services\WhatsApp\WhatsAppRouterService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class Phase18ComprehensiveTestSuiteTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;

    protected User $adminUserA;
    protected User $managerUserA;
    protected User $cashierUserA;
    protected User $staffUserA;
    protected User $adminUserB;

    protected Buyer $buyerA;
    protected Buyer $buyerB;
    protected Item_category $categoryJeans;
    protected Item_category $categoryShirts;
    protected StockManagement $productJeansA;
    protected StockManagement $productShirtsA;

    protected BaleService $baleService;
    protected SaleService $saleService;
    protected PaymentService $paymentService;
    protected PickupService $pickupService;
    protected InvoiceAmendmentService $amendmentService;
    protected ExpenseService $expenseService;
    protected BankDepositService $depositService;
    protected EodReconciliationService $eodService;
    protected ReconciliationService $reconciliationService;
    protected OtpService $otpService;
    protected WhatsAppRouterService $routerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->baleService = app(BaleService::class);
        $this->saleService = app(SaleService::class);
        $this->paymentService = app(PaymentService::class);
        $this->pickupService = app(PickupService::class);
        $this->amendmentService = app(InvoiceAmendmentService::class);
        $this->expenseService = app(ExpenseService::class);
        $this->depositService = app(BankDepositService::class);
        $this->eodService = app(EodReconciliationService::class);
        $this->reconciliationService = app(ReconciliationService::class);
        $this->otpService = app(OtpService::class);
        $this->routerService = app(WhatsAppRouterService::class);

        $this->companyA = Company::create(['company_name' => 'Orvell Accra Wholesale', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi Wholesale', 'currency' => 'GHS']);

        $this->adminUserA = User::create([
            'name'       => 'Accra Admin',
            'email'      => 'admin.accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->adminUserA->assignRole('Admin');

        $this->managerUserA = User::create([
            'name'       => 'Accra Manager',
            'email'      => 'manager.accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->managerUserA->assignRole('Manager');

        $this->cashierUserA = User::create([
            'name'       => 'Accra Cashier',
            'email'      => 'cashier.accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->cashierUserA->assignRole('Cashier');

        $this->staffUserA = User::create([
            'name'       => 'Accra Staff',
            'email'      => 'staff.accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $this->staffUserA->assignRole('Staff');

        $this->adminUserB = User::create([
            'name'       => 'Kumasi Admin',
            'email'      => 'admin.kumasi@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyB->id,
        ]);
        $this->adminUserB->assignRole('Admin');

        $this->buyerA = Buyer::create([
            'name'            => 'Kofi Mensah',
            'whatsapp_number' => '+233241112233',
            'company_id'      => $this->companyA->id,
        ]);

        $this->buyerB = Buyer::create([
            'name'            => 'Kwame Nkrumah',
            'whatsapp_number' => '+233242223344',
            'company_id'      => $this->companyB->id,
        ]);

        $this->categoryJeans = Item_category::create(['category_name' => 'Denim Jeans Grade A', 'category_type' => 'Garments']);
        $this->categoryShirts = Item_category::create(['category_name' => 'Cotton Shirts Grade A', 'category_type' => 'Garments']);

        $this->productJeansA = StockManagement::create([
            'name'       => 'Denim Jeans Grade A',
            'category'   => $this->categoryJeans->id,
            'company_id' => $this->companyA->id,
            'price'      => 300.00,
            'stock'      => 0,
        ]);

        $this->productShirtsA = StockManagement::create([
            'name'       => 'Cotton Shirts Grade A',
            'category'   => $this->categoryShirts->id,
            'company_id' => $this->companyA->id,
            'price'      => 200.00,
            'stock'      => 0,
        ]);
    }

    /**
     * Mandatory Acceptance Scenario 1: Cross-Company Access Prevention
     */
    public function test_cross_company_access_prevention(): void
    {
        $containerB = Container::create(['supplier_name' => 'Kumasi Supplier', 'company_id' => $this->companyB->id]);
        $baleB = $this->baleService->createBale([
            'container_id'     => $containerB->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 500.00,
        ], $this->companyB->id);

        $saleB = $this->saleService->createSale([
            'buyer_id' => $this->buyerB->id,
            'items'    => [['bale_ids' => [$baleB->id], 'unit_price' => 500.00]],
        ], $this->companyB->id);
        $invoiceB = $saleB['invoice'];

        $adminTokenA = $this->adminUserA->createToken('token_a')->plainTextToken;

        // 1. Company A Admin attempts to record payment on Company B invoice -> REJECTED (422)
        $paymentRes = $this->withHeaders(['Authorization' => 'Bearer ' . $adminTokenA])
            ->postJson('/api/payment/cash', [
                'company_id' => $this->companyA->id,
                'invoice_id' => $invoiceB->id,
                'amount'     => 500.00,
            ]);
        $paymentRes->assertStatus(422);

        // 2. Company A Admin attempts to query Company B pickup code -> REJECTED (422)
        $pickupRes = $this->withHeaders(['Authorization' => 'Bearer ' . $adminTokenA])
            ->postJson('/api/pickup/validate', [
                'company_id'  => $this->companyA->id,
                'pickup_code' => $saleB['order']->pickup_code,
            ]);
        $pickupRes->assertStatus(422);

        // 3. Company A lists expenses -> Only Company A records returned (0 items for Company A)
        $this->expenseService->recordExpense([
            'amount'   => 150.00,
            'category' => 'Utilities',
        ], $this->adminUserB->id, $this->companyB->id);

        $expListRes = $this->withHeaders(['Authorization' => 'Bearer ' . $adminTokenA])
            ->getJson('/api/expenses/list');
        $expListRes->assertStatus(200);
        $this->assertEquals(0, $expListRes->json('data.total'));
    }

    /**
     * Mandatory Acceptance Scenario 2: Cross-Company Reference Prevention
     */
    public function test_cross_company_reference_prevention(): void
    {
        $containerB = Container::create(['supplier_name' => 'Kumasi Supplier', 'company_id' => $this->companyB->id]);
        $baleB = $this->baleService->createBale([
            'container_id'     => $containerB->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 400.00,
        ], $this->companyB->id);

        // Attempting to create sale in Company A referencing Company B's physical Bale -> MUST THROW ValidationException
        $this->expectException(ValidationException::class);

        $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$baleB->id], 'unit_price' => 400.00]],
        ], $this->companyA->id);
    }

    /**
     * Mandatory Acceptance Scenario 3: Duplicate Bale Reservation Prevention
     */
    public function test_duplicate_bale_reservation_prevention(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 350.00,
        ], $this->companyA->id);

        // Sale 1 successfully reserves the bale
        $sale1 = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 350.00]],
        ], $this->companyA->id);

        $this->assertEquals('reserved', $bale->fresh()->status);

        // Sale 2 attempts to reserve the same bale -> MUST FAIL
        $this->expectException(ValidationException::class);
        $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 350.00]],
        ], $this->companyA->id);
    }

    /**
     * Mandatory Acceptance Scenario 4: Original Invoice Immutability
     */
    public function test_original_invoice_immutability(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 300.00]],
        ], $this->companyA->id);
        $invoice = $sale['invoice'];

        $originalTotal = (float) $invoice->total_amount;
        $originalSubtotal = (float) $invoice->subtotal;
        $originalNumber = $invoice->invoice_number;

        // 1. Record Partial Cash Payment
        $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 100.00,
        ], $this->cashierUserA->id, $this->companyA->id);

        $invoice->refresh();
        $this->assertEquals($originalTotal, (float) $invoice->total_amount, 'Total amount must be strictly immutable');
        $this->assertEquals($originalSubtotal, (float) $invoice->subtotal, 'Subtotal must be strictly immutable');
        $this->assertEquals($originalNumber, $invoice->invoice_number, 'Invoice number must be strictly immutable');
        $this->assertEquals(100.00, (float) $invoice->paid_amount);
        $this->assertEquals('partial', $invoice->payment_status);

        // 2. Submit Amendment Request
        $this->amendmentService->requestAmendment($invoice->id, [
            'reason'            => 'Price discount request',
            'requested_changes' => ['discount_amount' => 50.00],
        ], $this->staffUserA->id, $this->companyA->id);

        $invoice->refresh();
        $this->assertEquals($originalTotal, (float) $invoice->total_amount, 'Invoice total must not change upon amendment request');
    }

    /**
     * Mandatory Acceptance Scenario 5: Invoice Amendment Versioning Sequence
     */
    public function test_invoice_amendment_versioning_sequence(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale1 = $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 200.00], $this->companyA->id);
        $bale2 = $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryShirts->id, 'selling_price' => 150.00], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale1->id], 'unit_price' => 200.00]],
        ], $this->companyA->id);
        $invoiceV1 = $sale['invoice'];

        // Submit Amendment Request to replace bale1 with bale2
        $request = $this->amendmentService->requestAmendment($invoiceV1->id, [
            'reason'            => 'Customer changed mind to shirts',
            'requested_changes' => [
                'items' => [
                    ['bale_id' => $bale2->id, 'unit_price' => 150.00, 'quantity' => 1],
                ],
            ],
        ], $this->staffUserA->id, $this->companyA->id);

        // Admin approves amendment
        $invoiceV2 = $this->amendmentService->approveAmendment($request->id, $this->adminUserA->id, $this->companyA->id);

        // Assertions:
        // 1. Original invoice status is amended
        $this->assertEquals('amended', $invoiceV1->fresh()->status);
        $this->assertEquals(200.00, (float) $invoiceV1->fresh()->total_amount, 'Original invoice financial total remains intact');

        // 2. Versioned invoice created with amendment letter A
        $this->assertEquals('A', $invoiceV2->amendment_version);
        $this->assertStringEndsWith('-A', $invoiceV2->invoice_number);
        $this->assertEquals(150.00, (float) $invoiceV2->total_amount);

        // 3. Physical inventory correctly swapped: bale1 restored to available, bale2 marked reserved
        $this->assertEquals('available', $bale1->fresh()->status);
        $this->assertEquals('reserved', $bale2->fresh()->status);
    }

    /**
     * Mandatory Acceptance Scenario 6: Product Stock vs Bale Inventory Reconciliation
     */
    public function test_product_stock_vs_bale_inventory_reconciliation(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 250.00], $this->companyA->id);
        $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 250.00], $this->companyA->id);
        $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 250.00], $this->companyA->id);

        // Total available physical bales = 3
        $this->productJeansA->refresh();
        $this->assertEquals(3, (int) $this->productJeansA->stock);

        // Artificially create a drift in catalogue stock aggregate
        $this->productJeansA->update(['stock' => 99]);

        // Run integrity verification -> discrepancy detected
        $integrityCheck = $this->reconciliationService->verifyStockIntegrity($this->companyA->id);
        $this->assertEquals('discrepancy_found', $integrityCheck['status']);
        $this->assertEquals(1, $integrityCheck['discrepancy_count']);
        $this->assertEquals(96, $integrityCheck['discrepancies'][0]['variance']);

        // Repair discrepancy
        $repairResult = $this->reconciliationService->repairStockDiscrepancy($this->categoryJeans->id, $this->companyA->id, 'Reconciliation audit fix');
        $this->assertTrue($repairResult['status']);
        $this->assertEquals(3, $repairResult['corrected_stock']);
        $this->assertEquals(3, (int) $this->productJeansA->fresh()->stock, 'Catalogue aggregate must be restored to match physical bales');

        // Post-repair integrity check is balanced
        $finalCheck = $this->reconciliationService->verifyStockIntegrity($this->companyA->id);
        $this->assertEquals('balanced', $finalCheck['status']);
    }

    /**
     * Mandatory Acceptance Scenario 7: Duplicate WhatsApp Webhook Idempotency
     */
    public function test_duplicate_whatsapp_webhook_idempotency(): void
    {
        $payload = [
            'instance' => 'accra_hub',
            'data'     => [
                'from'      => '+233241112233',
                'body'      => 'catalog',
                'timestamp' => 1723190000,
            ],
        ];

        // 1. First webhook hit -> Processed and queued
        $res1 = $this->postJson('/api/whatsapp/chatterly', $payload);
        $res1->assertStatus(200)->assertJson(['ok' => true, 'queued' => true]);

        // 2. Duplicate webhook hit with identical timestamp/payload -> Deduped
        $res2 = $this->postJson('/api/whatsapp/chatterly', $payload);
        $res2->assertStatus(200)->assertJson(['ok' => true, 'skipped' => 'duplicate']);
    }

    /**
     * Mandatory Acceptance Scenario 8: Duplicate Paystack Webhook Idempotency
     */
    public function test_duplicate_paystack_webhook_idempotency(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 300.00], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 300.00]],
        ], $this->companyA->id);
        $invoice = $sale['invoice'];

        $reference = 'PAYSTACK-DEDUP-' . time();
        $payloadData = [
            'event' => 'charge.success',
            'data'  => [
                'reference' => $reference,
                'amount'    => 30000, // 300.00 GHS in pesewas
                'currency'  => 'GHS',
                'status'    => 'success',
                'customer'  => ['email' => 'buyer@orvell.com'],
                'metadata'  => ['invoice_id' => $invoice->id],
            ],
        ];

        $secret = 'test_paystack_secret_key_12345';
        config(['paystack.secret_key' => $secret]);
        $jsonPayload = json_encode($payloadData);
        $signature = hash_hmac('sha512', $jsonPayload, $secret);

        // 1. First webhook hit -> Processed
        $res1 = $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $jsonPayload
        );
        $res1->assertStatus(200);

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->payment_status);
        $this->assertEquals(300.00, (float) $invoice->paid_amount);
        $this->assertEquals(1, Payment::where('payment_reference', $reference)->count());

        // 2. Duplicate webhook hit -> Idempotent response, no duplicate payment created
        $res2 = $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $jsonPayload
        );
        $res2->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertEquals(1, Payment::where('payment_reference', $reference)->count(), 'Duplicate payment records must not be created');
        $this->assertEquals(300.00, (float) $invoice->fresh()->paid_amount, 'Paid amount must not be double counted');
    }

    /**
     * Mandatory Acceptance Scenario 9: OTP Expiry and Reuse Prevention
     */
    public function test_otp_expiry_and_reuse_prevention(): void
    {
        // 1. Expired OTP Rejection
        EmailOtp::create([
            'email'       => 'expired@orvell.com',
            'otp_hash'    => Hash::make('654321'),
            'type'        => 'register',
            'expires_at'  => now()->subMinutes(15),
            'verified_at' => null,
            'attempts'    => 0,
        ]);

        $verifyExpired = $this->otpService->verify('expired@orvell.com', '654321', 'register');
        $this->assertFalse($verifyExpired['status']);
        $this->assertEquals('Invalid or expired OTP', $verifyExpired['message']);

        // 2. Single-use OTP cannot be reused
        EmailOtp::create([
            'email'       => 'singleuse@orvell.com',
            'otp_hash'    => Hash::make('112233'),
            'type'        => 'register',
            'expires_at'  => now()->addMinutes(10),
            'verified_at' => null,
            'attempts'    => 0,
        ]);

        $firstVerify = $this->otpService->verify('singleuse@orvell.com', '112233', 'register');
        $this->assertTrue($firstVerify['status']);

        $secondVerify = $this->otpService->verify('singleuse@orvell.com', '112233', 'register');
        $this->assertFalse($secondVerify['status'], 'Already used OTP cannot be reused');
        $this->assertEquals('Invalid or expired OTP', $secondVerify['message']);
    }

    /**
     * Mandatory Acceptance Scenario 10: OTP Rate Limiting
     */
    public function test_otp_rate_limiting(): void
    {
        Mail::fake();
        $email = 'ratelimit@orvell.com';

        // 1st request
        $this->otpService->generateAndSend($email, 'register');
        // 2nd request
        $this->otpService->generateAndSend($email, 'register');
        // 3rd request
        $this->otpService->generateAndSend($email, 'register');

        // 4th request within 15 minutes must throw ValidationException
        $this->expectException(ValidationException::class);
        $this->otpService->generateAndSend($email, 'register');
    }

    /**
     * Scenario 11: Concurrent Bale Reservation Concurrency Lock
     */
    public function test_concurrent_bale_reservation_concurrency_lock(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 300.00], $this->companyA->id);

        // Buyer 1 successfully reserves the single available bale
        $sale1 = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 300.00]],
        ], $this->companyA->id);
        $this->assertNotNull($sale1['order']);

        // Buyer 2 attempting to buy Jeans Grade A -> Stock is depleted (0 available)
        $failed = false;
        try {
            $this->saleService->createSale([
                'buyer_id' => $this->buyerA->id,
                'items'    => [['item_category_id' => $this->categoryJeans->id, 'quantity' => 1, 'unit_price' => 300.00]],
            ], $this->companyA->id);
        } catch (ValidationException $e) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Second sale must fail due to zero available bales');
        $this->assertEquals(0, (int) $this->productJeansA->fresh()->stock);
    }

    /**
     * Scenario 12: Concurrent Payment Lockout Prevents Overpayment
     */
    public function test_concurrent_payment_lockout_prevents_overpayment(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale(['container_id' => $container->id, 'item_category_id' => $this->categoryJeans->id, 'selling_price' => 200.00], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 200.00]],
        ], $this->companyA->id);
        $invoice = $sale['invoice'];

        // 1. Pay 150.00 (Remaining = 50.00)
        $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 150.00,
        ], $this->cashierUserA->id, $this->companyA->id);

        // 2. Attempting to pay 100.00 (exceeds remaining 50.00) -> MUST FAIL
        $overpaid = false;
        try {
            $this->paymentService->recordCashPayment([
                'invoice_id' => $invoice->id,
                'amount'     => 100.00,
            ], $this->cashierUserA->id, $this->companyA->id);
        } catch (ValidationException $e) {
            $overpaid = true;
        }

        $this->assertTrue($overpaid, 'Payment exceeding outstanding balance must be rejected');
        $this->assertEquals(150.00, (float) $invoice->fresh()->paid_amount);
        $this->assertEquals('partial', $invoice->fresh()->payment_status);
    }

    /**
     * Scenario 13: Multi-Company Security Matrix on Elevated Roles
     */
    public function test_multi_company_security_matrix_on_elevated_roles(): void
    {
        $adminTokenA = $this->adminUserA->createToken('admin_a')->plainTextToken;

        // Admin of Company A attempts to generate EOD for Company B
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $adminTokenA])
            ->postJson('/api/reconciliation/eod/generate', [
                'company_id' => $this->companyB->id, // Unauthorized company context!
                'date'       => now()->toDateString(),
            ]);

        // EOD Service binds reconciliation strictly to user's assigned company (Company A)
        $response->assertStatus(200);
        $this->assertEquals($this->companyA->id, $response->json('data.company_id'));
        $this->assertNotEquals($this->companyB->id, $response->json('data.company_id'));
    }

    /**
     * Scenario 14: Complete End-to-End System Lifecycle Verification
     */
    public function test_complete_end_to_end_system_lifecycle_verification(): void
    {
        // 1. Container Arrival & Bale Unpacking
        $container = Container::create(['supplier_name' => 'GlobalTex UK', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryJeans->id,
            'selling_price'    => 450.00,
        ], $this->companyA->id);

        $this->assertEquals('available', $bale->status);
        $this->assertEquals(1, (int) $this->productJeansA->fresh()->stock);

        // 2. Buyer Order Placement
        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [['bale_ids' => [$bale->id], 'unit_price' => 450.00]],
        ], $this->companyA->id);

        $order = $sale['order'];
        $invoice = $sale['invoice'];
        $this->assertEquals('reserved', $bale->fresh()->status);
        $this->assertEquals('unpaid', $invoice->payment_status);
        $this->assertNotNull($order->pickup_code);

        // 3. Counter Cash Settlement
        $payResult = $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 450.00,
        ], $this->cashierUserA->id, $this->companyA->id);

        $this->assertEquals('paid', $invoice->fresh()->payment_status);

        // 4. Warehouse Pickup Validation & Release
        $pickupVal = $this->pickupService->validatePickupCode($order->pickup_code, $this->companyA->id);
        $this->assertTrue($pickupVal['valid']);
        $this->assertEquals('paid', $pickupVal['payment_status']);
        $this->assertEquals(1, $pickupVal['reserved_bales_count']);

        $releaseResult = $this->pickupService->releaseOrderBales($order->pickup_code, $this->staffUserA->id, $this->companyA->id, 'Dispatched to vehicle');
        $this->assertTrue($releaseResult['success']);
        $this->assertEquals('released', $bale->fresh()->status);
        $this->assertEquals('completed', $order->fresh()->status);

        // 5. Operational Expense Logging
        $expense = $this->expenseService->recordExpense([
            'amount'   => 50.00,
            'category' => 'Transport',
        ], $this->staffUserA->id, $this->companyA->id);
        $this->assertEquals(50.00, (float) $expense->amount);

        // 6. Cashier Bank Deposit
        $deposit = $this->depositService->recordDeposit([
            'amount'           => 400.00,
            'bank_name'        => 'GCB Bank',
            'reference_number' => 'DEP-E2E-' . time(),
        ], $this->cashierUserA->id, $this->companyA->id);
        $this->assertEquals(400.00, (float) $deposit->amount);

        // 7. EOD Reconciliation Snapshot Generation
        $eodResult = $this->eodService->generateDailyReconciliation(now()->toDateString(), $this->adminUserA->id, $this->companyA->id);
        $this->assertEquals(450.00, (float) $eodResult['total_sales_amount']);
        $this->assertEquals(450.00, (float) $eodResult['total_cash_collected']);
        $this->assertEquals(50.00, (float) $eodResult['total_expenses']);
        $this->assertEquals(400.00, (float) $eodResult['total_bank_deposits']);

        // 8. Audit Trail Verification
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cash_recorded', 'company_id' => $this->companyA->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bale.pickup_completed', 'company_id' => $this->companyA->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'eod.reconciliation_generated', 'company_id' => $this->companyA->id]);
    }
}

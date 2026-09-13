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
use App\Models\Payment;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\BaleService;
use App\Services\SaleService;
use App\Services\PaymentService;
use App\Services\PaystackService;

class Phase9PaymentsCashierTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected Buyer $buyerA;
    protected Buyer $buyerB;
    protected User $cashierUserA;
    protected User $cashierUserB;
    protected Item_category $categoryDresses;

    protected BaleService $baleService;
    protected SaleService $saleService;
    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baleService = app(BaleService::class);
        $this->saleService = app(SaleService::class);
        $this->paymentService = app(PaymentService::class);

        // 2 distinct companies
        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);
        $this->companyB = Company::create(['company_name' => 'Orvell Kumasi', 'currency' => 'GHS']);

        // Cashiers
        $this->cashierUserA = User::create([
            'name'       => 'Accra Cashier',
            'email'      => 'cashier_accra@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);

        $this->cashierUserB = User::create([
            'name'       => 'Kumasi Cashier',
            'email'      => 'cashier_kumasi@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyB->id,
        ]);

        // Buyers
        $this->buyerA = Buyer::create([
            'name'            => 'Kofi Mensah',
            'business_name'   => 'Mensah Clothing',
            'whatsapp_number' => '+233241112233',
            'company_id'      => $this->companyA->id,
        ]);

        $this->buyerB = Buyer::create([
            'name'            => 'Abena Darko',
            'business_name'   => 'Darko Boutique',
            'whatsapp_number' => '+233242223344',
            'company_id'      => $this->companyB->id,
        ]);

        $this->categoryDresses = Item_category::create(['category_name' => 'Summer Dresses Grade A']);
    }

    public function test_cash_payment_partial_and_full_settlement_lifecycle(): void
    {
        // 1. Setup sale with total 500.00
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 200.00,
            'selling_price'    => 500.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 500.00],
            ],
        ], $this->companyA->id);

        $invoice = $sale['invoice'];
        $order = $sale['order'];

        $this->assertEquals(500.00, $invoice->total_amount);
        $this->assertEquals('unpaid', $invoice->payment_status);
        $this->assertEquals('pending', $order->payment_status);

        // 2. Partial Cash Payment of 200.00
        $partialResult = $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 200.00,
            'notes'      => 'First instalment deposit',
        ], $this->cashierUserA->id, $this->companyA->id);

        $this->assertEquals(300.00, $partialResult['outstanding_balance']);
        $this->assertEquals('partial', $partialResult['payment_status']);
        $this->assertEquals('partial', $invoice->fresh()->payment_status);
        $this->assertEquals(200.00, $invoice->fresh()->paid_amount);

        // 3. Final Cash Payment of 300.00
        $fullResult = $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 300.00,
            'notes'      => 'Final settlement',
        ], $this->cashierUserA->id, $this->companyA->id);

        $this->assertEquals(0.00, $fullResult['outstanding_balance']);
        $this->assertEquals('paid', $fullResult['payment_status']);
        $this->assertEquals('paid', $invoice->fresh()->payment_status);
        $this->assertEquals(500.00, $invoice->fresh()->paid_amount);
        $this->assertEquals('paid', $order->fresh()->payment_status);

        // 4. Assert Audit Log recorded
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cash_recorded']);
    }

    public function test_overpayment_and_invalid_amount_rejection(): void
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

        $invoice = $sale['invoice'];

        // 1. Reject 0.00 payment
        $this->expectException(ValidationException::class);
        $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 0.00,
        ], $this->cashierUserA->id, $this->companyA->id);

        // 2. Reject Overpayment (e.g. 500 on 400 balance)
        $this->expectException(ValidationException::class);
        $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 500.00,
        ], $this->cashierUserA->id, $this->companyA->id);
    }

    public function test_payment_preserves_invoice_immutability_and_does_not_release_bale(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 350.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 350.00],
            ],
        ], $this->companyA->id);

        $invoice = $sale['invoice'];
        $invoiceNumber = $invoice->invoice_number;

        // Pay full amount
        $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 350.00,
        ], $this->cashierUserA->id, $this->companyA->id);

        $freshInvoice = $invoice->fresh();

        // 1. IMMUTABILITY CHECK: Total amount, subtotal, line items, and invoice number must NOT change
        $this->assertEquals(350.00, $freshInvoice->total_amount);
        $this->assertEquals(350.00, $freshInvoice->subtotal);
        $this->assertEquals($invoiceNumber, $freshInvoice->invoice_number);
        $this->assertCount(1, $freshInvoice->items);
        $this->assertEquals(350.00, $freshInvoice->items->first()->total_price);

        // 2. INVENTORY CHECK: Physical Bale must REMAIN RESERVED, NOT RELEASED!
        $bale->refresh();
        $this->assertEquals('reserved', $bale->status);
        $this->assertNull($bale->released_at);
        $this->assertNull($bale->released_by);
    }

    public function test_multi_company_isolation_on_cashier_payments(): void
    {
        $containerB = Container::create(['supplier_name' => 'Supplier B', 'company_id' => $this->companyB->id]);
        $baleB = $this->baleService->createBale([
            'container_id'     => $containerB->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 300.00,
        ], $this->companyB->id);

        $saleB = $this->saleService->createSale([
            'buyer_id' => $this->buyerB->id,
            'items'    => [
                ['bale_ids' => [$baleB->id], 'unit_price' => 300.00],
            ],
        ], $this->companyB->id);

        $invoiceB = $saleB['invoice'];

        // Cashier from Company A attempts to record payment on Company B invoice -> MUST FAIL
        $this->expectException(ValidationException::class);
        $this->paymentService->recordCashPayment([
            'invoice_id' => $invoiceB->id,
            'amount'     => 300.00,
        ], $this->cashierUserA->id, $this->companyA->id);
    }

    public function test_paystack_online_payment_verification_and_idempotency(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 450.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 450.00],
            ],
        ], $this->companyA->id);

        $invoice = $sale['invoice'];

        // Mock PaystackService
        $mockPaystack = $this->createMock(PaystackService::class);
        $mockPaystack->method('verify')->willReturn([
            'status'   => 'success',
            'amount'   => 45000, // 450.00 in pesewas
            'currency' => 'GHS',
            'customer' => ['email' => 'kofi@buyer.com'],
            'metadata' => [
                'invoice_id' => $invoice->id,
                'order_id'   => $sale['order']->id,
            ],
        ]);

        $customPaymentService = new PaymentService(app(AuditService::class), $mockPaystack);

        // 1. Verify and record payment
        $result = $customPaymentService->verifyAndRecordPaystackPayment('PSTK_REF_998877', $this->companyA->id);

        $this->assertFalse($result['idempotent']);
        $this->assertEquals('paid', $result['payment_status']);
        $this->assertEquals(450.00, $invoice->fresh()->paid_amount);

        // 2. Idempotency check: Re-verifying the same reference must NOT duplicate payment
        $secondResult = $customPaymentService->verifyAndRecordPaystackPayment('PSTK_REF_998877', $this->companyA->id);

        $this->assertTrue($secondResult['idempotent']);
        $this->assertEquals(1, Payment::where('payment_reference', 'PSTK_REF_998877')->count());
    }

    public function test_api_payment_cash_endpoint(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 200.00],
            ],
        ], $this->companyA->id);

        $invoice = $sale['invoice'];

        $token = $this->cashierUserA->createToken('cashier_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/payment/cash', [
                'company_id' => $this->companyA->id,
                'invoice_id' => $invoice->id,
                'amount'     => 200.00,
                'notes'      => 'Cashier desk collection',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'        => true,
                'payment_status' => 'paid',
                'outstanding_balance' => 0.0,
            ]);

        $this->assertEquals('paid', $invoice->fresh()->payment_status);
    }

    public function test_paystack_webhook_signature_and_event_handling(): void
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
        $secret = 'test_paystack_secret_key_12345';
        config(['paystack.secret_key' => $secret]);

        $payloadArray = [
            'event' => 'charge.success',
            'data'  => [
                'reference' => 'PSTK_WEBHOOK_001',
                'amount'    => 50000,
                'currency'  => 'GHS',
                'customer'  => ['email' => 'buyer@orvell.com'],
                'metadata'  => ['invoice_id' => $invoice->id],
            ],
        ];
        $rawJson = json_encode($payloadArray);

        // 1. Invalid signature -> MUST be rejected (returns 400 or 401)
        $invalidResponse = $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => 'invalid_signature_hex', 'CONTENT_TYPE' => 'application/json'],
            $rawJson
        );

        $invalidResponse->assertStatus(400);

        // 2. Valid HMAC SHA512 signature -> MUST succeed
        $validSignature = hash_hmac('sha512', $rawJson, $secret);

        $validResponse = $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $validSignature, 'CONTENT_TYPE' => 'application/json'],
            $rawJson
        );

        $validResponse->assertStatus(200)
            ->assertJson(['ok' => true]);

        $this->assertEquals('paid', $invoice->fresh()->payment_status);
        $this->assertEquals(500.00, $invoice->fresh()->paid_amount);
    }

    public function test_concurrent_payment_lockout_prevents_overpayment(): void
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

        // Payment 1 consumes full balance (300.00)
        $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 300.00,
        ], $this->cashierUserA->id, $this->companyA->id);

        // Simultaneous / second payment attempt for 300.00 MUST fail with ValidationException
        $this->expectException(ValidationException::class);
        $this->paymentService->recordCashPayment([
            'invoice_id' => $invoice->id,
            'amount'     => 300.00,
        ], $this->cashierUserA->id, $this->companyA->id);
    }

    public function test_unauthorized_salesperson_cannot_record_cash_payment(): void
    {
        // 1. Create Spatie Roles
        $cashierRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Cashier']);
        $salespersonRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Salesperson']);

        // Assign roles
        $this->cashierUserA->assignRole($cashierRole);

        $salespersonUser = User::create([
            'name'       => 'Unauthorized Salesperson',
            'email'      => 'salesperson@orvell.com',
            'password'   => bcrypt('password123'),
            'company_id' => $this->companyA->id,
        ]);
        $salespersonUser->assignRole($salespersonRole);

        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 200.00,
        ], $this->companyA->id);

        $sale = $this->saleService->createSale([
            'buyer_id' => $this->buyerA->id,
            'items'    => [
                ['bale_ids' => [$bale->id], 'unit_price' => 200.00],
            ],
        ], $this->companyA->id);

        $invoice = $sale['invoice'];

        $salespersonToken = $salespersonUser->createToken('sales_token')->plainTextToken;

        // Salesperson attempts to record cash payment -> MUST BE REJECTED WITH 403 FORBIDDEN
        $response = $this->withHeader('Authorization', 'Bearer ' . $salespersonToken)
            ->postJson('/api/payment/cash', [
                'company_id' => $this->companyA->id,
                'invoice_id' => $invoice->id,
                'amount'     => 200.00,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        // Zero payments recorded
        $this->assertEquals(0, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertEquals('unpaid', $invoice->fresh()->payment_status);
    }
}

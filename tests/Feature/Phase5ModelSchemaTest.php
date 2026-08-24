<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Company;
use App\Models\Buyer;
use App\Models\Container;
use App\Models\Bale;
use App\Models\BaleBatch;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceAmendmentRequest;
use App\Models\Payment;
use App\Models\Release;
use App\Models\Expense;
use App\Models\BankDeposit;
use App\Models\DailySnapshot;
use App\Models\DailyReconciliation;
use App\Models\WhatsappConversation;
use App\Models\AuditLog;
use App\Models\EmailOtp;
use App\Models\Item_category;
use App\Models\StockManagement;
use App\Models\User;
use App\Models\Client;

class Phase5ModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_creation_and_auto_code(): void
    {
        $company = Company::create([
            'company_name' => 'Orvell Ghana Ltd',
            'currency'     => 'GHS',
        ]);

        $this->assertNotNull($company->uuid);
        $this->assertNotNull($company->company_code);
        $this->assertStringStartsWith('COMP-', $company->company_code);
        $this->assertEquals('Orvell Ghana Ltd', $company->company_name);
    }

    public function test_buyer_auto_id_and_categories(): void
    {
        $company = Company::create(['company_name' => 'Orvell Main', 'currency' => 'GHS']);

        $buyer1 = Buyer::create([
            'name'            => 'Kofi Mensah',
            'whatsapp_number' => '+233240000001',
            'category'        => 'REGULAR',
            'company_id'      => $company->id,
        ]);

        $buyer2 = Buyer::create([
            'name'            => 'Ama Serwaa',
            'whatsapp_number' => '+233240000002',
            'category'        => 'OCCASIONAL',
            'company_id'      => $company->id,
        ]);

        $this->assertEquals('BUY001', $buyer1->buyer_id);
        $this->assertEquals('BUY002', $buyer2->buyer_id);
        $this->assertEquals(1, Buyer::regular()->count());
        $this->assertEquals(1, Buyer::occasional()->count());
    }

    public function test_container_and_bale_traceability(): void
    {
        $company = Company::create(['company_name' => 'Orvell Main', 'currency' => 'GHS']);
        $category = Item_category::create([
            'category_name' => 'Dresses Grade A',
            'category_type' => 'Garments',
        ]);

        $container = Container::create([
            'supplier_name' => 'Global Textil Ltd',
            'company_id'    => $company->id,
            'arrival_date'  => '2026-08-01',
            'total_bales'   => 100,
            'remaining_bales'=> 100,
        ]);

        $this->assertStringStartsWith('CONT-', $container->container_number);

        $bale = Bale::create([
            'container_id'     => $container->id,
            'item_category_id' => $category->id,
            'company_id'       => $company->id,
            'cost_price'       => 150.00,
            'selling_price'    => 250.00,
            'status'           => 'available',
        ]);

        $this->assertStringStartsWith('BALE-', $bale->bale_code);
        $this->assertEquals($container->id, $bale->container->id);
        $this->assertEquals($category->id, $bale->category->id);
        $this->assertEquals(1, Bale::available()->count());
    }

    public function test_inventory_transaction_ledger(): void
    {
        $company = Company::create(['company_name' => 'Orvell Main', 'currency' => 'GHS']);

        $tx = InventoryTransaction::create([
            'company_id'       => $company->id,
            'transaction_type' => 'container_arrival',
            'quantity'         => 50,
            'unit_price'       => 120.00,
            'notes'            => 'Arrival of Cont 1',
        ]);

        $this->assertNotNull($tx->uuid);
        $this->assertEquals('container_arrival', $tx->transaction_type);
        $this->assertEquals(50, $tx->quantity);
    }

    public function test_order_invoice_payment_release_flow(): void
    {
        $company = Company::create(['company_name' => 'Orvell Main', 'currency' => 'GHS']);
        $buyer = Buyer::create(['name' => 'Buyer Test', 'company_id' => $company->id]);

        $order = Order::create([
            'buyer_id'       => $buyer->id,
            'company_id'     => $company->id,
            'order_quantity' => 2,
            'total_amount'   => 500.00,
            'order_amount'   => 500.00,
            'currency'       => 'GHS',
        ]);

        $this->assertStringStartsWith('ORD-', $order->order_no);
        $this->assertStringStartsWith('INV-', $order->invoice_code);
        $this->assertStringStartsWith('PKP-', $order->pickup_code);

        $invoice = Invoice::create([
            'order_id'     => $order->id,
            'buyer_id'     => $buyer->id,
            'company_id'   => $company->id,
            'subtotal'     => 500.00,
            'total_amount' => 500.00,
            'status'       => 'finalized',
        ]);

        $this->assertStringStartsWith('INV-', $invoice->invoice_number);

        $item = InvoiceItem::create([
            'invoice_id'       => $invoice->id,
            'item_description' => 'Grade A Bale',
            'quantity'         => 2,
            'unit_price'       => 250.00,
            'total_price'      => 500.00,
        ]);

        $this->assertEquals(1, $invoice->items()->count());

        $payment = Payment::create([
            'invoice_id'     => $invoice->id,
            'order_id'       => $order->id,
            'buyer_id'       => $buyer->id,
            'company_id'     => $company->id,
            'amount'         => 500.00,
            'payment_method' => 'cash',
            'status'         => 'verified',
        ]);

        $this->assertStringStartsWith('PAY-', $payment->payment_number);

        $release = Release::create([
            'order_id'    => $order->id,
            'invoice_id'  => $invoice->id,
            'buyer_id'    => $buyer->id,
            'company_id'  => $company->id,
            'pickup_code' => $order->pickup_code,
            'status'      => 'released',
        ]);

        $this->assertStringStartsWith('REL-', $release->release_code);
    }

    public function test_invoice_amendment_request(): void
    {
        $company = Company::create(['company_name' => 'Orvell Main', 'currency' => 'GHS']);
        $buyer = Buyer::create(['name' => 'Buyer Test', 'company_id' => $company->id]);
        $user = User::create(['name' => 'Staff 1', 'email' => 'staff@orvell.com', 'password' => 'secret']);

        $invoice = Invoice::create([
            'buyer_id'     => $buyer->id,
            'company_id'   => $company->id,
            'total_amount' => 300.00,
            'status'       => 'finalized',
        ]);

        $request = InvoiceAmendmentRequest::create([
            'invoice_id'        => $invoice->id,
            'requested_by'      => $user->id,
            'reason'            => 'Price adjusted for loyalty buyer',
            'requested_changes' => ['new_total' => 280.00],
            'status'            => 'pending',
        ]);

        $this->assertNotNull($request->uuid);
        $this->assertEquals(1, InvoiceAmendmentRequest::pending()->count());
    }

    public function test_expenses_and_bank_deposits(): void
    {
        $company = Company::create(['company_name' => 'Orvell Main', 'currency' => 'GHS']);
        $user = User::create(['name' => 'Cashier 1', 'email' => 'cashier@orvell.com', 'password' => 'secret']);

        $expense = Expense::create([
            'company_id'   => $company->id,
            'staff_id'     => $user->id,
            'category'     => 'transport',
            'amount'       => 75.00,
            'description'  => 'Truck fuel',
            'expense_date' => '2026-08-09',
        ]);

        $this->assertStringStartsWith('EXP-', $expense->expense_number);

        $deposit = BankDeposit::create([
            'company_id'   => $company->id,
            'cashier_id'   => $user->id,
            'bank_name'    => 'GCB Bank',
            'amount'       => 5000.00,
            'deposit_date' => '2026-08-09',
        ]);

        $this->assertStringStartsWith('DEP-', $deposit->deposit_number);
    }

    public function test_daily_snapshot_and_reconciliation(): void
    {
        $company = Company::create(['company_name' => 'Orvell Main', 'currency' => 'GHS']);

        $snapshot = DailySnapshot::create([
            'company_id'          => $company->id,
            'snapshot_date'       => '2026-08-09',
            'opening_stock_units' => 200,
            'opening_stock_value' => 50000.00,
            'closing_stock_units' => 195,
            'closing_stock_value' => 48750.00,
        ]);

        $this->assertNotNull($snapshot->uuid);

        $reconciliation = DailyReconciliation::create([
            'company_id'          => $company->id,
            'reconciliation_date' => '2026-08-09',
            'total_sales_amount'  => 1250.00,
            'total_cash_collected'=> 1250.00,
            'status'              => 'balanced',
        ]);

        $this->assertEquals('balanced', $reconciliation->status);
    }

    public function test_email_otp_model_methods(): void
    {
        $otp = EmailOtp::create([
            'email'      => 'buyer@example.com',
            'otp_hash'   => password_hash('123456', PASSWORD_BCRYPT),
            'type'       => 'register',
            'expires_at' => now()->addMinutes(10),
            'attempts'   => 0,
        ]);

        $this->assertTrue($otp->isValid());
        $this->assertFalse($otp->isExpired());
        $this->assertFalse($otp->isVerified());

        $otp->update(['verified_at' => now()]);
        $this->assertTrue($otp->isVerified());
        $this->assertFalse($otp->isValid());
    }

    public function test_whatsapp_conversation_persistence(): void
    {
        $convo = WhatsappConversation::create([
            'wa_id'         => '233241112233',
            'role'          => 'buyer',
            'current_flow'  => 'order_placement',
            'current_step'  => 2,
            'state_payload' => ['category_id' => 1, 'qty' => 3],
        ]);

        $this->assertNotNull($convo->uuid);
        $this->assertEquals('buyer', $convo->role);
        $this->assertEquals(2, $convo->current_step);
    }

    public function test_audit_log_creation(): void
    {
        $user = User::create(['name' => 'Admin User', 'email' => 'admin@orvell.com', 'password' => 'secret']);

        $log = AuditLog::create([
            'user_id'        => $user->id,
            'actor_name'     => 'Admin User',
            'action'         => 'bale.create',
            'auditable_type' => 'App\Models\Bale',
            'auditable_id'   => 101,
            'new_values'     => ['bale_code' => 'BALE-2026-000101'],
        ]);

        $this->assertNotNull($log->uuid);
        $this->assertEquals('bale.create', $log->action);
    }
}

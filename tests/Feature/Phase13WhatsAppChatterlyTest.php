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
use App\Models\AgentDetails;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\AuditLog;
use App\Services\BaleService;
use App\Services\WhatsAppAgentService;
use App\Services\PaystackService;
use App\Services\ChatterlyService;

class Phase13WhatsAppChatterlyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Item_category $categoryDresses;
    protected BaleService $baleService;
    protected WhatsAppAgentService $whatsappBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baleService = app(BaleService::class);
        $this->whatsappBot = app(WhatsAppAgentService::class);

        $this->companyA = Company::create(['company_name' => 'Orvell Accra', 'currency' => 'GHS']);

        $this->categoryDresses = Item_category::create([
            'category_name' => 'Summer Dresses Grade A',
            'category_type' => 'Garments',
        ]);
    }

    public function test_customer_buyer_onboarding_via_whatsapp(): void
    {
        $phone = '+233245556677';

        // 1. Initial Greeting / Step 0
        $res1 = $this->whatsappBot->handle('customer', $phone, 'Kofi Mensah', $this->companyA->id);
        $this->assertStringContainsString('What is your *email address*', $res1['reply']);

        // Assert Buyer auto-created
        $this->assertDatabaseHas('buyers', [
            'whatsapp_number' => $phone,
            'name'            => 'Kofi Mensah',
        ]);

        // 2. Email step / Step 1
        $res2 = $this->whatsappBot->handle('customer', $phone, 'kofi@orvellbuyer.com', $this->companyA->id);
        $this->assertStringContainsString('Which *country* or *city*', $res2['reply']);

        // 3. Location step / Step 2 -> Complete
        $res3 = $this->whatsappBot->handle('customer', $phone, 'Accra, Ghana', $this->companyA->id);
        $this->assertStringContainsString('Setup complete', $res3['reply']);

        // Assert Buyer and Customer updated
        $buyer = Buyer::where('whatsapp_number', $phone)->first();
        $this->assertEquals('kofi@orvellbuyer.com', $buyer->email);
        $this->assertEquals('Accra, Ghana', $buyer->city);
    }

    public function test_conversational_order_placement_with_physical_bale_reservation(): void
    {
        $phone = '+233241119988';

        // Create available physical bales
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'cost_price'       => 150.00,
            'selling_price'    => 350.00,
        ], $this->companyA->id);

        $this->assertEquals('available', $bale->fresh()->status);

        // Send direct order command
        $result = $this->whatsappBot->handle('customer', $phone, 'order Summer Dresses Grade A 1', $this->companyA->id);

        $this->assertEquals('order_created', $result['action']);
        $this->assertStringContainsString('Order Created', $result['reply']);
        $this->assertStringContainsString('Pickup Code', $result['reply']);

        // Assert Physical Bale is RESERVED
        $this->assertEquals('reserved', $bale->fresh()->status);

        // Assert Order and Invoice created
        $order = Order::where('buyer_id', Buyer::where('whatsapp_number', $phone)->value('id'))->first();
        $this->assertNotNull($order);
        $this->assertEquals(350.00, $order->total_amount);
        $this->assertEquals('pending', $order->payment_status);
        $this->assertEquals('pending', $order->pickup_status);

        // Assert Inbound & Outbound messages persisted
        $this->assertDatabaseHas('whatsapp_messages', [
            'sender_wa_id' => $phone,
            'direction'    => 'inbound',
        ]);
        $this->assertDatabaseHas('whatsapp_messages', [
            'recipient_wa_id' => $phone,
            'direction'       => 'outbound',
        ]);
    }

    public function test_conversational_payment_link_generation(): void
    {
        $phone = '+233241119988';

        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $bale = $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        // Place order first
        $this->whatsappBot->handle('customer', $phone, 'order Summer Dresses Grade A 1', $this->companyA->id);

        // Mock PaystackService
        $mockPaystack = $this->createMock(PaystackService::class);
        $mockPaystack->method('initialize')->willReturn([
            'url'       => 'https://checkout.paystack.com/test-checkout-url',
            'reference' => 'PSTK_REF_WA_001',
        ]);

        $this->app->instance(PaystackService::class, $mockPaystack);
        $customBot = app(WhatsAppAgentService::class);

        // Buyer asks to "pay"
        $payResult = $customBot->handle('customer', $phone, 'pay', $this->companyA->id);

        $this->assertEquals('payment_link', $payResult['action']);
        $this->assertStringContainsString('https://checkout.paystack.com/test-checkout-url', $payResult['reply']);
    }

    public function test_admin_whatsapp_commands_and_stock_reporting(): void
    {
        $container = Container::create(['supplier_name' => 'EuroTex', 'company_id' => $this->companyA->id]);
        $this->baleService->createBale([
            'container_id'     => $container->id,
            'item_category_id' => $this->categoryDresses->id,
            'selling_price'    => 300.00,
        ], $this->companyA->id);

        // Admin checks stock
        $stockRes = $this->whatsappBot->handle('admin', '+233240000000', 'stock', $this->companyA->id);
        $this->assertEquals('admin_command', $stockRes['action']);
        $this->assertStringContainsString('Live Warehouse Stock Report', $stockRes['reply']);
        $this->assertStringContainsString('Available: 1', $stockRes['reply']);

        // Admin checks today's sales
        $salesRes = $this->whatsappBot->handle('admin', '+233240000000', 'sales', $this->companyA->id);
        $this->assertStringContainsString("Today's Sales Summary", $salesRes['reply']);
    }

    public function test_human_support_escalation(): void
    {
        $phone = '+233243334455';

        $res = $this->whatsappBot->handle('customer', $phone, 'I have a complaint, talk to human please', $this->companyA->id);

        $this->assertEquals('escalated', $res['action']);
        $this->assertStringContainsString('connecting you with our warehouse management team', $res['reply']);

        // Assert Audit Log
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.escalated']);
    }

    public function test_api_whatsapp_webhook_and_chatterly_endpoint(): void
    {
        // 1. Simple webhook endpoint
        $response = $this->postJson('/api/whatsapp/webhook', [
            'agent_type' => 'customer',
            'from'       => '+233249998877',
            'message'    => 'hi',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok'     => true,
                'action' => 'greeting',
            ]);

        // 2. Chatterly Gateway endpoint
        $agent = AgentDetails::create([
            'name'       => 'test_instance',
            'token'      => 'test_instance_token',
            'agent_type' => 'customer',
            'status'     => 'connected',
        ]);

        $chatterlyPayload = [
            'instance' => 'test_instance',
            'data'     => [
                'from' => '233249998877@s.whatsapp.net',
                'body' => 'shop',
                'type' => 'chat',
            ],
        ];

        $chatterlyRes = $this->postJson('/api/whatsapp/chatterly', $chatterlyPayload);

        $chatterlyRes->assertStatus(200)
            ->assertJson(['ok' => true]);
    }
}

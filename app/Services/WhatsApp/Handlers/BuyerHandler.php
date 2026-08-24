<?php

namespace App\Services\WhatsApp\Handlers;

use App\Models\Buyer;
use App\Models\Customer;
use App\Models\Item_category;
use App\Models\Order;
use App\Models\Bale;
use App\Models\Company;
use App\Models\WhatsappConversation;
use App\Services\SaleService;
use App\Services\PaystackService;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\AgentPromptService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BuyerHandler
{
    protected SaleService $saleService;
    protected PaystackService $paystackService;
    protected AuditService $auditService;
    protected InventoryService $inventoryService;
    protected AgentPromptService $promptService;
    protected string $defaultCurrency = 'GHS';

    public function __construct(
        SaleService $saleService,
        PaystackService $paystackService,
        AuditService $auditService,
        InventoryService $inventoryService,
        AgentPromptService $promptService
    ) {
        $this->saleService = $saleService;
        $this->paystackService = $paystackService;
        $this->auditService = $auditService;
        $this->inventoryService = $inventoryService;
        $this->promptService = $promptService;
    }

    public function handle(string $from, string $t, WhatsappConversation $conv): array
    {
        $lc = mb_strtolower(trim($t));

        // 1. Authoritative Buyer Resolution / Auto-Registration
        $buyer = $this->resolveBuyer($from, 1);
        $customer = $this->customerFor($from, $buyer->id);

        $pendKey = 'wa:pend:' . $buyer->id;

        // 2. Human Support Escalation (Priority check)
        foreach (['refund', 'complaint', 'talk to human', 'speak to', 'human', 'manager', 'support', 'agent'] as $kw) {
            if (str_contains($lc, $kw)) {
                Cache::forget($pendKey);
                $conv->update(['current_flow' => 'escalated', 'current_step' => 1]);

                $this->auditService->log(
                    action: 'whatsapp.escalated',
                    auditable: $buyer,
                    newValues: ['phone' => $from, 'reason' => $t],
                    companyId: 1
                );

                return ['action' => 'escalated', 'reply' => "🙏 I am connecting you with our warehouse management team. An agent will message you directly shortly."];
            }
        }

        // 3. Onboarding Flow if Buyer profile is incomplete
        if ($buyer->wasRecentlyCreated || (int) $customer->onboarding_status < 6) {
            $reply = $this->handleOnboarding($buyer, $customer, $t);
            if ($reply !== null) {
                return ['action' => 'onboarding', 'reply' => $reply];
            }
        }

        // 4. Quick Order Command: "order <category/product> <qty>"
        if (preg_match('/^order\s+(.+?)\s+(\d+)\s*$/i', $t, $m)) {
            Cache::forget($pendKey);
            return $this->placeOrder($buyer, trim($m[1]), (int) $m[2], 1);
        }
        if (preg_match('/^order\s+(\d+)\s+(.+)$/i', $t, $m)) {
            Cache::forget($pendKey);
            return $this->placeOrder($buyer, trim($m[2]), (int) $m[1], 1);
        }

        // 5. Quantity Response for Pending Category
        if (preg_match('/^(\d{1,4})\s*(bales|bale|pcs|qty)?$/i', $lc, $m)) {
            $catId = Cache::get($pendKey);
            if ($catId && ($category = Item_category::find($catId))) {
                Cache::forget($pendKey);
                return $this->placeOrder($buyer, $category->category_name, (int) $m[1], 1);
            }
        }

        // 6. Payment Link Request: "pay"
        if (str_contains($lc, 'pay')) {
            $order = Order::where('buyer_id', $buyer->id)
                ->where('payment_status', 'pending')
                ->latest()
                ->first();

            if (!$order) {
                return ['action' => 'replied', 'reply' => "You have no pending unpaid orders. Send *shop* to browse available stock. 🛍️"];
            }

            $reference = $order->payment_reference ?: ('PSTK-' . $order->order_no . '-' . strtoupper(Str::random(5)));
            $email = $buyer->email ?: (preg_replace('/[^0-9]/', '', $buyer->whatsapp_number) . '@orvellwholesale.com');

            $pay = $this->paystackService->initialize((float) $order->total_amount, $email, $reference, [
                'order_id'   => $order->id,
                'invoice_id' => $order->invoice?->id,
                'buyer_id'   => $buyer->id,
            ]);

            if (!$pay) {
                return ['action' => 'replied', 'reply' => "💳 Order #{$order->order_no} — {$this->defaultCurrency} " . number_format((float) $order->total_amount, 2) . "\nPayment gateway is momentarily busy. Please reply *pay* in a moment."];
            }

            $order->update([
                'payment_reference' => $pay['reference'],
                'payment_method'    => 'paystack',
            ]);

            $this->auditService->log(
                action: 'whatsapp.payment_initiated',
                auditable: $order,
                newValues: ['reference' => $pay['reference'], 'amount' => $order->total_amount],
                companyId: 1
            );

            return ['action' => 'payment_link', 'reply' => "💳 *Pay for Order #{$order->order_no}*\nAmount: {$this->defaultCurrency} " . number_format((float) $order->total_amount, 2) . "\n\n👉 Tap here to pay securely:\n{$pay['url']}\n\nYou will receive pickup confirmation as soon as payment is verified ✅"];
        }

        // 7. Check Order Status / Pickup Code: "status" or "pickup"
        if (str_contains($lc, 'status') || str_contains($lc, 'pickup') || str_contains($lc, 'code')) {
            $latestOrder = Order::where('buyer_id', $buyer->id)->latest()->first();
            if (!$latestOrder) {
                return ['action' => 'replied', 'reply' => "No order history found for your account. Reply *shop* to start shopping."];
            }

            return ['action' => 'order_status', 'reply' => "📦 *Order #{$latestOrder->order_no}*\nTotal: {$this->defaultCurrency} " . number_format((float) $latestOrder->total_amount, 2) . "\nPayment: *{$latestOrder->payment_status}*\nPickup Status: *{$latestOrder->pickup_status}*\nPickup Code: *{$latestOrder->pickup_code}*"];
        }

        // 8. Browse / Shop Categories
        if (str_contains($lc, 'shop') || str_contains($lc, 'catalog') || str_contains($lc, 'browse') || str_contains($lc, 'menu') || str_contains($lc, 'items') || str_contains($lc, 'stock')) {
            return $this->categoriesList(1);
        }

        // 9. Greeting
        if (in_array($lc, ['hi', 'hello', 'hey', 'start', 'good morning', 'good evening', 'good afternoon'], true)) {
            $name = $buyer->name ? " {$buyer->name}" : '';
            return ['action' => 'greeting', 'reply' => "👋 *Welcome to Orvell Wholesale*{$name}!\n\nReply *shop* to view available bale categories, or send a *category name* to order."];
        }

        // 10. Direct Category selection
        $cat = $this->findCategory($t);
        if ($cat) {
            $avail = Bale::where('item_category_id', $cat->id)
                ->where('company_id', 1)
                ->where('status', 'available')
                ->count();

            if ($avail > 0) {
                Cache::put($pendKey, $cat->id, 900);
                $sampleBale = Bale::where('item_category_id', $cat->id)
                    ->where('company_id', 1)
                    ->where('status', 'available')
                    ->first();

                $price = $sampleBale ? number_format((float) $sampleBale->selling_price, 2) : '300.00';

                return ['action' => 'replied', 'reply' => "🛒 *{$cat->category_name}*\nPrice: {$this->defaultCurrency} {$price} per bale\nAvailable Stock: {$avail} bales\n\nHow many bales would you like? Reply with a number (e.g. *1* or *2*)."];
            } else {
                return ['action' => 'replied', 'reply' => "😔 *{$cat->category_name}* is currently out of stock. Send *shop* to see other available categories."];
            }
        }

        // // Fallback
        // return ['action' => 'help', 'reply' => "🤖 *How can I help you?*\n• *shop* — browse available bales\n• *order <category> <qty>* — place quick order\n• *pay* — pay for latest pending order\n• *pickup* — get your pickup code\n• *support* — talk to a human"];
        // 11. Conversational & Custom IQ Prompt Delegation
        return $this->promptService->handleConversationalMessage('customer', $from, $t, 1, ['buyer_id' => $buyer->id]);
    }

    private function placeOrder(Buyer $buyer, string $categoryOrName, int $qty, int $companyId): array
    {
        $cat = $this->findCategory($categoryOrName);
        if (!$cat) {
            return ['action' => 'replied', 'reply' => "❌ Category \"{$categoryOrName}\" not found. Reply *shop* to see our available stock."];
        }

        $qty = max(1, $qty);

        $availableBales = Bale::where('item_category_id', $cat->id)
            ->where('company_id', $companyId)
            ->where('status', 'available')
            ->take($qty)
            ->get();

        if ($availableBales->count() < $qty) {
            return ['action' => 'replied', 'reply' => "😔 Only {$availableBales->count()} bales of *{$cat->category_name}* are available right now. Please order a smaller quantity."];
        }

        try {
            $unitPrice = (float) $availableBales->first()->selling_price;

            $saleResult = $this->saleService->createSale([
                'buyer_id' => $buyer->id,
                'items'    => [
                    [
                        'bale_ids'          => $availableBales->pluck('id')->toArray(),
                        'item_category_id'  => $cat->id,
                        'item_description'  => $cat->category_name,
                        'unit_price'        => $unitPrice,
                    ],
                ],
            ], $companyId);

            $order = $saleResult['order'];

            $this->auditService->log(
                action: 'whatsapp.order_placed',
                auditable: $order,
                newValues: [
                    'order_no'    => $order->order_no,
                    'pickup_code' => $order->pickup_code,
                    'total'       => $order->total_amount,
                ],
                companyId: $companyId
            );

            return ['action' => 'order_created', 'reply' => "✅ *Order Created!*\nOrder #{$order->order_no}\n*{$cat->category_name}* x{$qty} bales\nTotal: {$this->defaultCurrency} " . number_format((float) $order->total_amount, 2) . "\nPickup Code: *{$order->pickup_code}*\n\nReply *pay* to get your secure payment link! 💳"];
        } catch (\Throwable $e) {
            Log::error('[whatsapp-buyer] placeOrder failed: ' . $e->getMessage());
            return ['action' => 'error', 'reply' => "❌ Could not complete your order: " . $e->getMessage()];
        }
    }

    private function categoriesList(int $companyId): array
    {
        $categories = Item_category::all();
        $lines = [];

        foreach ($categories as $cat) {
            $count = Bale::where('item_category_id', $cat->id)
                ->where('company_id', $companyId)
                ->where('status', 'available')
                ->count();

            if ($count > 0) {
                $lines[] = "• *{$cat->category_name}* ({$count} bales available)";
            }
        }

        if (empty($lines)) {
            return ['action' => 'replied', 'reply' => "📦 New containers arriving soon! Please check back shortly."];
        }

        $list = implode("\n", $lines);
        return ['action' => 'catalogue', 'reply' => "🗂️ *Available Bale Categories*\n\n{$list}\n\nTo order, reply with the *category name* (e.g. *{$categories->first()->category_name}*) or *order <category> <qty>*."];
    }

    private function resolveBuyer(string $from, int $companyId): Buyer
    {
        $buyer = Buyer::where('whatsapp_number', $from)
            ->where('company_id', $companyId)
            ->first();

        if (!$buyer) {
            $buyer = Buyer::create([
                'company_id'      => $companyId,
                'name'            => 'WhatsApp Buyer ' . substr(preg_replace('/[^0-9]/', '', $from), -4),
                'whatsapp_number' => $from,
            ]);
        }

        return $buyer;
    }

    private function customerFor(string $waId, ?int $buyerId = null): Customer
    {
        $customer = Customer::where('wa_id', $waId)->first();
        if (!$customer) {
            $customer = Customer::create([
                'wa_id'             => $waId,
                'whatsapp_number'   => $waId,
                'name'              => 'WhatsApp ' . substr(preg_replace('/[^0-9]/', '', $waId), -4),
                'address'           => 'WhatsApp',
                'onboarding_status' => 0,
            ]);
        }

        return $customer;
    }

    private function handleOnboarding(Buyer $buyer, Customer $customer, string $text): ?string
    {
        $val = trim($text);
        $step = (int) $customer->onboarding_status;

        // Step 0: Name
        if ($step === 0) {
            if (preg_match('/^(shop|order|pay|menu|hi|hello|hey|catalog|list)\b/i', $val)) {
                $customer->update(['onboarding_status' => 6]);
                return null;
            }
            if ($val === '' || mb_strlen($val) > 60) {
                return "👋 *Welcome to Orvell Wholesale!*\nPlease reply with your *full name* to complete registration.";
            }

            $customer->update(['name' => $val, 'onboarding_status' => 1]);
            $buyer->update(['name' => $val]);

            return "Thanks *{$val}*! 📧\nWhat is your *email address*?";
        }

        // Step 1: Email
        if ($step === 1) {
            if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                return "Please enter a valid *email address* (e.g. name@example.com).";
            }

            $customer->update(['email' => $val, 'onboarding_status' => 2]);
            $buyer->update(['email' => $val]);

            return "🌍 Which *country* or *city* are you located in?";
        }

        // Step 2: Location / Complete
        if ($step === 2) {
            $customer->update(['city' => $val, 'address' => $val, 'onboarding_status' => 6]);
            $buyer->update(['city' => $val, 'address' => $val]);

            return "🎉 Setup complete! You are now registered with Orvell Wholesale.\n\nReply *shop* to browse our container arrivals!";
        }

        return null;
    }

    private function findCategory(string $text): ?Item_category
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return null;
        }

        $c = Item_category::where('category_name', $text)->orWhere('code', $text)->first();
        if ($c) {
            return $c;
        }

        $lt = mb_strtolower($text);
        foreach (Item_category::all() as $cat) {
            $cn = mb_strtolower((string) $cat->category_name);
            if ($lt === $cn || str_contains($cn, $lt) || str_contains($lt, $cn)) {
                return $cat;
            }
        }

        return null;
    }
}

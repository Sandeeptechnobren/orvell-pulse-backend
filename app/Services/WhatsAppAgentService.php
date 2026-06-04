<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Item_category;
use App\Models\Order;
use App\Models\StockManagement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WhatsApp agent "brain" for the Orvell Pulse backend.
 *
 *  Admin agent    → manage stock: show stock, low stock, add/restock, reduce, update price,
 *                   add product, pending orders, today's sales.
 *  Customer agent → shop the catalogue, place an order, ask to pay, escalate to a human.
 *
 * Operates directly on the live `products` / `orders` / `customers` tables (orvell_pulse).
 * The reply text is returned to the controller, which forwards it to Chatterly.
 */
class WhatsAppAgentService
{
    private int $lowStock = 5;

    private string $currency = 'GHS';

    public function handle(string $agentType, string $from, string $message): array
    {
        $message = trim($message);
        Log::info('[wa-in] '.$agentType.' '.$from.': '.$message);

        $result = $agentType === 'admin'
            ? $this->admin($message)
            : $this->customer($from, $message);

        Log::info('[wa-out] '.$result['action'].' -> '.str_replace("\n", ' | ', $result['reply']));

        return $result;
    }

    /* ----------------------------------------------------------------- ADMIN */

    private function admin(string $t): array
    {
        $lc = mb_strtolower($t);

        // add product <name> <price> in <category>
        if (preg_match('/^add\s+product\s+(.+?)\s+([\d.]+)\s+in\s+(.+)$/i', $t, $m)) {
            $d   = $this->tenantDefaults();
            $cat = $this->findCategory(trim($m[3]));
            $p   = StockManagement::create([
                'name'      => trim($m[1]),
                'price'     => (float) $m[2],
                'stock'     => 0,
                'currency'  => $this->currency,
                'type'      => 'physical',
                'is_active' => 1,
                'category'  => $cat?->id ?? 0,
                'client_id' => $d['client_id'],
                'space_id'  => $d['space_id'],
            ]);
            $cn = $cat?->category_name ?? 'Uncategorized';

            return $this->out('admin_command', "✅ Product added: *{$p->name}* — {$this->currency} ".number_format((float) $m[2], 2)." · 🏷️ {$cn} (SKU {$p->sku}). Stock: 0.\nRestock: add <qty> {$p->name}");
        }

        // set <product> category <category>
        if (preg_match('/^set\s+(.+?)\s+category\s+(?:to\s+)?(.+)$/i', $t, $m)) {
            $p = $this->find(trim($m[1]));
            if (! $p) {
                return $this->out('admin_command', "❌ \"{$m[1]}\" not found.");
            }
            $cat = $this->findCategory(trim($m[2]));
            if (! $cat) {
                return $this->out('admin_command', "❌ Category \"{$m[2]}\" not found. Type *categories* to see options.");
            }
            $p->category = $cat->id;
            $p->save();

            return $this->out('admin_command', "✅ *{$p->name}* → 🏷️ {$cat->category_name}");
        }

        // categories — list item categories
        if (preg_match('/^(show\s+|list\s+)?categor(y|ies)\s*$/i', $t)) {
            $cats = Item_category::orderBy('category_name')->get();
            if ($cats->isEmpty()) {
                return $this->out('admin_command', 'No categories yet.');
            }

            return $this->out('admin_command', "🏷️ *Categories*\n".$cats->map(fn ($c) => "• {$c->category_name}")->implode("\n"));
        }

        // add product <name> <price>
        if (preg_match('/^add\s+product\s+(.+?)\s+([\d.]+)\s*$/i', $t, $m)) {
            $d = $this->tenantDefaults();
            $p = StockManagement::create([
                'name'      => trim($m[1]),
                'price'     => (float) $m[2],
                'stock'     => 0,
                'currency'  => $this->currency,
                'type'      => 'physical',
                'is_active' => 1,
                'client_id' => $d['client_id'],
                'space_id'  => $d['space_id'],
            ]);

            return $this->out('admin_command', "✅ Product added: *{$p->name}* — {$this->currency} ".number_format((float) $m[2], 2)." (SKU {$p->sku}). Stock: 0.\nRestock with: add <qty> {$p->name}");
        }

        // add <qty> <name>  |  restock <qty> <name>
        if (preg_match('/^(?:add|restock|stock\s*in)\s+(\d+)\s+(.+)$/i', $t, $m)) {
            $p = $this->find(trim($m[2]));
            if (! $p) {
                return $this->out('admin_command', "❌ \"{$m[2]}\" not found. Create it: add product <name> <price>");
            }
            $p->stock = (int) $p->stock + (int) $m[1];
            $p->save();

            return $this->out('admin_command', "✅ Added {$m[1]} to *{$p->name}*. New stock: {$p->stock}");
        }

        // remove/reduce <qty> <name>
        if (preg_match('/^(?:remove|reduce|out)\s+(\d+)\s+(.+)$/i', $t, $m)) {
            $p = $this->find(trim($m[2]));
            if (! $p) {
                return $this->out('admin_command', "❌ \"{$m[2]}\" not found.");
            }
            $p->stock = max(0, (int) $p->stock - (int) $m[1]);
            $p->save();

            return $this->out('admin_command', "✅ Reduced *{$p->name}* by {$m[1]}. New stock: {$p->stock}");
        }

        // update <name> price to <amount>
        if (preg_match('/^update\s+(.+?)\s+price\s+to\s+([\d.]+)/i', $t, $m)) {
            $p = $this->find(trim($m[1]));
            if (! $p) {
                return $this->out('admin_command', "❌ \"{$m[1]}\" not found.");
            }
            $p->price = (float) $m[2];
            $p->save();

            return $this->out('admin_command', "✅ *{$p->name}* price updated to {$this->currency} ".number_format((float) $m[2], 2));
        }

        // low stock
        if (str_contains($lc, 'low stock') || str_contains($lc, 'low-stock')) {
            $low = StockManagement::where('is_active', 1)->where('stock', '<=', $this->lowStock)->orderBy('stock')->get();
            if ($low->isEmpty()) {
                return $this->out('admin_command', "✅ No low-stock products (threshold {$this->lowStock}).");
            }

            return $this->out('admin_command', "⚠️ *Low stock*\n".$low->map(fn ($p) => "• {$p->name}: {$p->stock} left")->implode("\n"));
        }

        // pending orders
        if (str_contains($lc, 'pending') && str_contains($lc, 'order')) {
            $pending = Order::with('product')->where('payment_status', 'pending')->latest()->take(10)->get();
            if ($pending->isEmpty()) {
                return $this->out('admin_command', 'No pending orders. 🎉');
            }

            return $this->out('admin_command', "🕓 *Pending orders*\n".$pending->map(function ($o) {
                $name = optional($o->product)->name ?? 'item';

                return "• #{$o->id} {$name} x{$o->order_quantity} — {$this->currency} ".number_format((float) $o->order_amount, 2);
            })->implode("\n"));
        }

        // sales / today
        if (str_contains($lc, 'sales') || str_contains($lc, 'today')) {
            $today = now()->toDateString();
            $paid  = (float) Order::whereDate('created_at', $today)->where('payment_status', 'paid')->sum('order_amount');
            $count = Order::whereDate('created_at', $today)->count();

            return $this->out('admin_command', "💰 *Today*\nPaid sales: {$this->currency} ".number_format($paid, 2)."\nOrders placed: {$count}");
        }

        // stock / inventory report
        if (preg_match('/\b(stock|inventory|stocks|catalogue|catalog|products?)\b/i', $lc)) {
            $items = StockManagement::orderBy('name')->get();
            if ($items->isEmpty()) {
                return $this->out('admin_command', 'No products yet. Add one: add product <name> <price>');
            }
            $lines = $items->map(function ($p) {
                $flag = ((int) $p->stock <= $this->lowStock) ? ' ⚠️' : '';

                return "• {$p->name} — {$this->currency} ".number_format((float) $p->price, 2)." — Qty: {$p->stock}{$flag}";
            })->implode("\n");

            return $this->out('admin_command', "📦 *Stock report* ({$items->count()} products · {$items->sum('stock')} units)\n{$lines}");
        }

        return $this->out('admin_command', $this->adminHelp());
    }

    /* -------------------------------------------------------------- CUSTOMER */

    private function customer(string $from, string $t): array
    {
        $lc = mb_strtolower(trim($t));

        // Auto-register + onboarding: capture the customer's full name on first contact.
        $customer = $this->customerFor($from);

        if ($customer->wasRecentlyCreated) {
            return $this->out('replied', $this->onboardWelcome());
        }

        if ((int) $customer->onboarding_status < 6) {
            $reply = $this->handleOnboarding($customer, $t);
            if ($reply !== null) {
                return $this->out('replied', $reply);
            }
            // (a command at the name step → onboarding skipped, continue below)
        }

        $pendKey = 'wa:pend:'.$customer->id;

        // Escalation → human.
        foreach (['refund', 'complaint', 'talk to human', 'speak to', 'human', 'manager', 'support'] as $kw) {
            if (str_contains($lc, $kw)) {
                Cache::forget($pendKey);

                return $this->out('escalated', "🙏 I'm connecting you with our team — someone will message you shortly.");
            }
        }

        // Explicit order: "order <name> <qty>" or "order <qty> <name>".
        if (preg_match('/^order\s+(.+?)\s+(\d+)\s*$/i', $t, $m)) {
            Cache::forget($pendKey);

            return $this->placeOrder($customer, trim($m[1]), (int) $m[2]);
        }
        if (preg_match('/^order\s+(\d+)\s+(.+)$/i', $t, $m)) {
            Cache::forget($pendKey);

            return $this->placeOrder($customer, trim($m[2]), (int) $m[1]);
        }

        // A plain number while a product is pending → place that order.
        if (preg_match('/^(\d{1,4})\s*(pcs|pc|pieces|qty|nos)?$/i', $lc, $m)) {
            $pid = Cache::get($pendKey);
            if ($pid && ($p = StockManagement::find($pid))) {
                Cache::forget($pendKey);

                return $this->placeOrder($customer, $p->name, (int) $m[1]);
            }
        }

        // Pay → generate a real Paystack checkout link for the latest pending order.
        if (str_contains($lc, 'pay')) {
            $order = Order::where('customer_id', $customer->id)->where('payment_status', 'pending')->latest()->first();
            if (! $order) {
                return $this->out('replied', 'You have no pending orders. Send *shop* to start. 🛍️');
            }

            $reference = $order->payment_reference ?: ('ORV-'.$order->id.'-'.strtoupper(Str::random(5)));
            $email     = $customer->email ?: (preg_replace('/[^0-9]/', '', (string) $customer->whatsapp_number).'@orvellwholesale.com');

            $pay = app(\App\Services\PaystackService::class)->initialize((float) $order->order_amount, $email, $reference, [
                'order_id' => $order->id,
                'customer' => $customer->whatsapp_number,
            ]);

            if (! $pay) {
                return $this->out('replied', "💳 Order #{$order->id} — {$this->currency} ".number_format((float) $order->order_amount, 2)."\nPayment link abhi ban nahi paya 🙏 Thodi der me *pay* dobara bhejo.");
            }

            $order->payment_reference = $pay['reference'];
            $order->payment_method    = 'paystack';
            $order->save();

            return $this->out('replied', "💳 *Pay for Order #{$order->id}*\nAmount: {$this->currency} ".number_format((float) $order->order_amount, 2)."\n\n👉 Tap to pay securely:\n{$pay['url']}\n\nPayment hote hi confirmation milega ✅");
        }

        // "all" → the full catalogue (every product).
        if (in_array($lc, ['all', 'all products', 'everything', 'full list'], true)) {
            return $this->catalogue();
        }

        // Shop / browse → category list.
        if (str_contains($lc, 'shop') || str_contains($lc, 'catalog') || str_contains($lc, 'browse') || str_contains($lc, 'categor') || str_contains($lc, 'product') || in_array($lc, ['list', 'menu', 'items', 'stock'], true)) {
            return $this->categoriesList();
        }

        // Greeting → personalised welcome back.
        if (in_array($lc, ['hi', 'hii', 'hy', 'hyy', 'hello', 'helo', 'hey', 'start', 'good morning', 'good evening', 'good afternoon'], true)) {
            $nm = ($customer->name && ! str_starts_with($customer->name, 'WhatsApp ')) ? ' '.explode(' ', trim($customer->name))[0] : '';

            return $this->out('replied', "👋 *Welcome back to Orvell Wholesale*{$nm}!\nReply *shop* to browse, or just send a *product name* to order it.");
        }

        // Category name → show that category's products.
        $cat = $this->findCategory($t);
        if ($cat && StockManagement::where('is_active', 1)->where('category', $cat->id)->where('stock', '>', 0)->exists()) {
            return $this->productsInCategory($cat);
        }

        // Product name (alone or mentioned) → remember it and ask for quantity.
        $prod = $this->findActive($t);
        if ($prod) {
            Cache::put($pendKey, $prod->id, 900);

            return $this->out('replied', "🛒 *{$prod->name}* — {$this->currency} ".number_format((float) $prod->price, 2)." (in stock: {$prod->stock})\n\nHow many would you like? Reply with a *number* (e.g. *1*), or *order {$prod->name} <qty>*.");
        }

        // Order intent without a recognised product → show the catalogue.
        if ($lc === 'yes' || preg_match('/\b(order|buy|purchase|want|need|chahiye|lena|kharid)\b/i', $lc)) {
            return $this->catalogue("Sure! Here's what we have 👇");
        }

        // Fallback.
        return $this->out('replied', "🤖 I can help you shop:\n• *shop* — see our products\n• send a *product name* (e.g. Zara) to order it\n• *order <product> <qty>* — quick order\n• *pay* — pay for your order");
    }

    /* --------------------------------------------------------------- HELPERS */

    private function find(string $name): ?StockManagement
    {
        $name = trim($name);

        return StockManagement::where('name', $name)->first()
            ?: StockManagement::where('sku', $name)->first()
            ?: StockManagement::where('name', 'like', '%'.$name.'%')->first();
    }

    /** Find an ACTIVE product the customer likely meant (exact, sku, or name mentioned in the text). */
    private function findActive(string $text): ?StockManagement
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return null;
        }

        $exact = StockManagement::where('is_active', 1)
            ->where(fn ($q) => $q->where('name', $text)->orWhere('sku', $text))
            ->first();
        if ($exact) {
            return $exact;
        }

        $lt    = mb_strtolower($text);
        $ltns  = preg_replace('/\s+/', '', $lt); // spaceless, e.g. "hp15laptop"
        $words = array_filter(preg_split('/\s+/', $lt), fn ($w) => mb_strlen($w) >= 3);

        $best      = null;
        $bestScore = 0.0;

        foreach (StockManagement::where('is_active', 1)->get() as $prod) {
            $pn = mb_strtolower((string) $prod->name);
            if ($pn === '') {
                continue;
            }
            $pnns  = preg_replace('/\s+/', '', $pn);
            $first = preg_split('/\s+/', $pn)[0] ?? $pn;

            // Substring (normal + spaceless) — handles partials and "Hp15 laptop".
            if (str_contains($lt, $pn) || str_contains($pn, $lt)
                || ($ltns !== '' && ($pnns === $ltns || str_contains($ltns, $pnns) || str_contains($pnns, $ltns)))) {
                return $prod;
            }

            // Fuzzy — handles typos like "Sumsung" → "Samsung".
            similar_text($ltns, $pnns, $a);
            $b = 0.0;
            foreach ($words as $w) {
                similar_text($w, $first, $p);
                $b = max($b, $p);
            }
            $score = max($a, $b);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $prod;
            }
        }

        return ($best && $bestScore >= 72) ? $best : null;
    }

    /** Render the in-stock product catalogue. */
    private function catalogue(string $intro = '🛍️ *Our products*'): array
    {
        $items = StockManagement::where('is_active', 1)->where('stock', '>', 0)->orderBy('name')->get();
        if ($items->isEmpty()) {
            return $this->out('replied', "We're restocking 🙏 Please check back soon.");
        }
        $lines = $items->map(fn ($p) => "• *{$p->name}* — {$this->currency} ".number_format((float) $p->price, 2))->implode("\n");

        return $this->out('replied', "{$intro}\n{$lines}\n\nTo order: just send the *product name* (e.g. {$items->first()->name}), or *order <product> <qty>*.");
    }

    /** Find an item category by name / code (exact, singular/plural, or clear prefix). */
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
            if ($cn === '') {
                continue;
            }
            if ($lt === $cn || rtrim($lt, 's') === rtrim($cn, 's')) {
                return $cat;
            }
            if (mb_strlen($lt) >= 4 && str_contains($cn, $lt)) {
                return $cat;
            }
        }

        return null;
    }

    /** List categories that currently have in-stock products. */
    private function categoriesList(): array
    {
        $cats = Item_category::orderBy('category_name')->get()->filter(
            fn ($c) => StockManagement::where('is_active', 1)->where('category', $c->id)->where('stock', '>', 0)->exists()
        );

        if ($cats->isEmpty()) {
            return $this->catalogue(); // nothing categorised yet → show the full list
        }

        $lines = $cats->map(function ($c) {
            $n = StockManagement::where('is_active', 1)->where('category', $c->id)->where('stock', '>', 0)->count();

            return "• *{$c->category_name}* ({$n})";
        })->implode("\n");

        return $this->out('replied', "🗂️ *Browse by category*\n{$lines}\n\nReply a *category name* to see items, send a *product name* to order, or type *all* for everything.");
    }

    /** List the in-stock products in a category. */
    private function productsInCategory(Item_category $cat): array
    {
        $items = StockManagement::where('is_active', 1)->where('category', $cat->id)->where('stock', '>', 0)->orderBy('name')->get();
        if ($items->isEmpty()) {
            return $this->out('replied', "No items in *{$cat->category_name}* right now. Type *shop* to see other categories.");
        }
        $lines = $items->map(fn ($p) => "• *{$p->name}* — {$this->currency} ".number_format((float) $p->price, 2))->implode("\n");

        return $this->out('replied', "🏷️ *{$cat->category_name}*\n{$lines}\n\nTo order, send the *product name* (e.g. {$items->first()->name}).");
    }

    /** Create an order for a product + quantity, decrement stock, and confirm. */
    private function placeOrder(Customer $customer, string $name, int $qty): array
    {
        $p = $this->find($name);
        if (! $p || ! $p->is_active) {
            return $this->out('replied', "❌ \"{$name}\" isn't available. Send *shop* to see what we have.");
        }
        $qty = max(1, $qty);
        if ((int) $p->stock < $qty) {
            return $this->out('replied', "😔 Only {$p->stock} of *{$p->name}* left. Try a smaller quantity.");
        }

        $amount = (float) $p->price * $qty;

        $order = Order::create([
            'client_id'      => $p->client_id,
            'space_id'       => $p->space_id,
            'customer_id'    => $customer->id,
            'product_id'     => $p->id,
            'order_quantity' => $qty,
            'order_amount'   => $amount,
            'payment_status' => 'pending',
            'status'         => 'pending',
            'pickup_code'    => 'PKP-'.strtoupper(Str::random(6)),
            'address'        => $customer->address ?: 'WhatsApp order',
        ]);

        $p->stock = max(0, (int) $p->stock - $qty);
        $p->save();

        return $this->out('order_created', "🛒 *Order placed!*\n*{$p->name}* x{$qty}\nTotal: {$this->currency} ".number_format($amount, 2)."\nOrder #{$order->id} · Pickup code {$order->pickup_code}\n\nReply *pay* to complete payment.");
    }

    private function customerFor(string $waId): Customer
    {
        $digits = preg_replace('/[^0-9]/', '', $waId) ?: $waId;

        // Look up by the stable WhatsApp id (LID / chat id); the real phone is captured in onboarding.
        return Customer::firstOrCreate(
            ['wa_id' => $waId],
            ['whatsapp_number' => $waId, 'name' => 'WhatsApp '.substr($digits, -4), 'address' => 'WhatsApp']
        );
    }

    /** First-contact onboarding message — introduces the business and asks for the name. */
    private function onboardWelcome(): string
    {
        return "👋 *Welcome to Orvell Wholesale!*\n\n".
            "We're one of West Africa's largest second-hand clothing importers.\n\n".
            "Register now to:\n".
            "✅ Receive new container alerts\n".
            "✅ Get instant digital receipts\n".
            "✅ Track your orders via WhatsApp\n\n".
            "Let's get you set up! 🚀\n\n".
            "What is your *full name*?";
    }

    /**
     * Multi-step onboarding driven by onboarding_status:
     *   0 name · 1 email · 2 country · 3 city · 4 pin/ZIP · 5 preferred categories · 6 done.
     * Email, country, city and pin are required; address is auto-filled as "city, country".
     * Returns the next prompt, or null to fall through.
     */
    private function handleOnboarding(Customer $customer, string $text): ?string
    {
        $val  = trim($text);
        $step = (int) $customer->onboarding_status;

        // Step 0 — full name.
        if ($step === 0) {
            // A command instead of a name → skip onboarding and process it normally.
            if (preg_match('/^(shop|order|pay|menu|hi|hello|hey|catalog|catalogue|list)\b/i', $val)) {
                $customer->onboarding_status = 6;
                $customer->save();

                return null;
            }
            if ($val === '' || mb_strlen($val) > 60) {
                return 'Please reply with your *full name* to continue. 🙏';
            }
            $customer->name              = $val;
            $customer->onboarding_status = 1;
            $customer->save();

            return "Thanks *{$val}*! 📧\nWhat's your *email address*?";
        }

        // Step 1 — email (required).
        if ($step === 1) {
            if (! filter_var($val, FILTER_VALIDATE_EMAIL)) {
                return "That doesn't look like a valid email 🤔\nPlease send your *email address*.";
            }
            $customer->email             = $val;
            $customer->onboarding_status = 2;
            $customer->save();

            return '🌍 Which *country* are you in?';
        }

        // Step 2 — country (required).
        if ($step === 2) {
            if ($val === '') {
                return 'Please tell us your *country*. 🌍';
            }
            $customer->country           = $val;
            $customer->onboarding_status = 3;
            $customer->save();

            return '🏙️ Your *city*?';
        }

        // Step 3 — city (required; also auto-fills address = "city, country").
        if ($step === 3) {
            if ($val === '') {
                return 'Please tell us your *city*. 🏙️';
            }
            $customer->city = $val;
            $addr = trim(implode(', ', array_filter([$customer->city, $customer->country])));
            if ($addr !== '') {
                $customer->address = $addr;
            }
            $customer->onboarding_status = 4;
            $customer->save();

            return '📮 Your *pin / ZIP code*?';
        }

        // Step 4 — pin / ZIP (required).
        if ($step === 4) {
            if ($val === '') {
                return 'Please send your *pin / ZIP code*. 📮';
            }
            $customer->zipcode           = $val;
            $customer->onboarding_status = 5;
            $customer->save();

            return "🛍️ What do you usually buy? (e.g. *men, women, children* — comma separated)";
        }

        // Step 5 — preferred categories (required; stored as a JSON array) → done.
        if ($step === 5) {
            $cats = array_values(array_filter(array_map(
                fn ($c) => trim(mb_strtolower($c)),
                explode(',', $val)
            )));
            if (empty($cats)) {
                return 'Please tell us what you usually buy (e.g. *men, women, children*). 🛍️';
            }
            $customer->preferred_categories = json_encode($cats);
            $customer->onboarding_status    = 6;
            $customer->save();

            // Onboarding complete — take the customer straight to the shop (no extra message).
            return $this->categoriesList()['reply'];
        }

        return null;
    }

    private function tenantDefaults(): array
    {
        $p = StockManagement::orderBy('id')->first();

        return ['client_id' => $p->client_id ?? 4, 'space_id' => $p->space_id ?? 1];
    }

    private function out(string $action, string $reply): array
    {
        return ['action' => $action, 'reply' => $reply];
    }

    private function adminHelp(): string
    {
        return "🤖 *Admin commands*\n".
            "• *show stock* — full inventory\n".
            "• *low stock* — items running low\n".
            "• *add <qty> <product>* — restock (e.g. add 20 Zara)\n".
            "• *remove <qty> <product>* — reduce stock\n".
            "• *add product <name> <price>* — new product\n".
            "• *update <product> price to <amount>*\n".
            "• *pending orders*\n".
            "• *today's sales*";
    }
}

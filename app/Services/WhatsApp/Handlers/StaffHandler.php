<?php

namespace App\Services\WhatsApp\Handlers;

use App\Models\Buyer;
use App\Models\Order;
use App\Models\Item_category;
use App\Models\WhatsappConversation;
use App\Models\User;
use App\Services\SaleService;
use App\Services\PickupService;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\AuditService;
use Illuminate\Support\Facades\Log;

class StaffHandler
{
    protected SaleService $saleService;
    protected PickupService $pickupService;
    protected ExpenseService $expenseService;
    protected InventoryService $inventoryService;
    protected AuditService $auditService;
    protected string $defaultCurrency = 'GHS';

    public function __construct(
        SaleService $saleService,
        PickupService $pickupService,
        ExpenseService $expenseService,
        InventoryService $inventoryService,
        AuditService $auditService
    ) {
        $this->saleService = $saleService;
        $this->pickupService = $pickupService;
        $this->expenseService = $expenseService;
        $this->inventoryService = $inventoryService;
        $this->auditService = $auditService;
    }

    public function handle(string $from, string $t, int $companyId, WhatsappConversation $conv, ?int $staffId = null): array
    {
        $lc = mb_strtolower(trim($t));

        // 1. Pickup Release Confirmation: "release <pickup_code>" or "confirm <pickup_code> released"
        if (preg_match('/^(?:release|confirm\s+release|dispatch)\s+([a-zA-Z0-9_-]+)(?:\s+(?:released|dispatched))?$/i', $t, $m) ||
            preg_match('/^confirm\s+([a-zA-Z0-9_-]+)\s+released$/i', $t, $m)) {
            $pickupCode = trim($m[1]);
            try {
                $result = $this->pickupService->releaseOrderBales($pickupCode, $staffId, $companyId, 'WhatsApp staff release');
                $order = $result['order'];
                $baleCount = count($result['released_bales']);

                return [
                    'action' => 'pickup_released',
                    'reply'  => "✅ *Pickup Dispatch Confirmed!*\nOrder #{$order->order_no}\nPickup Code: {$pickupCode}\nReleased Bales: {$baleCount} units\nStatus: *Completed*",
                ];
            } catch (\Throwable $e) {
                return [
                    'action' => 'error',
                    'reply'  => "❌ Release failed for code {$pickupCode}: " . $e->getMessage(),
                ];
            }
        }

        // 2. Operational Expense Logging: "expense <amount> <category> <description>"
        if (preg_match('/^expense\s+([\d.]+)\s+([a-zA-Z_-]+)(?:\s+(.+))?$/i', $t, $m)) {
            $amount = (float) $m[1];
            $category = trim($m[2]);
            $desc = isset($m[3]) ? trim($m[3]) : 'Staff recorded expense';

            try {
                $expense = $this->expenseService->recordExpense([
                    'company_id'  => $companyId,
                    'amount'      => $amount,
                    'category'    => $category,
                    'description' => $desc,
                ], $staffId ?? 1, $companyId);

                return [
                    'action' => 'expense_recorded',
                    'reply'  => "✅ *Expense Recorded*\nRef: #{$expense->expense_number}\nCategory: {$expense->category}\nAmount: {$this->defaultCurrency} " . number_format($expense->amount, 2) . "\nDescription: {$expense->description}",
                ];
            } catch (\Throwable $e) {
                return [
                    'action' => 'error',
                    'reply'  => "❌ Expense recording failed: " . $e->getMessage(),
                ];
            }
        }

        // 3. Quick Sale Creation: "sale <buyer_phone> <category> <qty>"
        if (preg_match('/^sale\s+(\+?[0-9]{9,15})\s+(.+?)\s+(\d+)\s*$/i', $t, $m)) {
            $buyerPhone = trim($m[1]);
            $categoryName = trim($m[2]);
            $qty = (int) $m[3];

            $cat = Item_category::where('category_name', $categoryName)
                ->orWhere('category_name', 'like', "%{$categoryName}%")
                ->first();

            if (!$cat) {
                return [
                    'action' => 'error',
                    'reply'  => "❌ Category \"{$categoryName}\" not found.",
                ];
            }

            $buyer = Buyer::firstOrCreate(
                ['whatsapp_number' => $buyerPhone, 'company_id' => $companyId],
                ['name' => 'Buyer ' . substr($buyerPhone, -4)]
            );

            try {
                $sale = $this->saleService->createSale([
                    'buyer_id' => $buyer->id,
                    'items'    => [
                        [
                            'item_category_id' => $cat->id,
                            'quantity'         => $qty,
                        ],
                    ],
                ], $companyId);

                $order = $sale['order'];
                return [
                    'action' => 'sale_created',
                    'reply'  => "✅ *Staff Sale Created!*\nOrder #{$order->order_no}\nBuyer: {$buyer->name} ({$buyerPhone})\nCategory: {$cat->category_name} x{$qty}\nTotal: {$this->defaultCurrency} " . number_format((float) $order->total_amount, 2) . "\nPickup Code: *{$order->pickup_code}*",
                ];
            } catch (\Throwable $e) {
                return [
                    'action' => 'error',
                    'reply'  => "❌ Sale creation failed: " . $e->getMessage(),
                ];
            }
        }

        // 4. Stock query: "stock" or "stock <category>"
        if (str_starts_with($lc, 'stock')) {
            $categories = Item_category::all();
            $lines = [];
            foreach ($categories as $cat) {
                $breakdown = $this->inventoryService->getStockBreakdown($cat->id, $companyId);
                $lines[] = "• *{$cat->category_name}*: {$breakdown['available_stock']} avail | {$breakdown['reserved_stock']} res";
            }

            return [
                'action' => 'staff_stock',
                'reply'  => "📦 *Warehouse Stock*\n" . implode("\n", $lines),
            ];
        }

        // Staff Help
        return [
            'action' => 'staff_help',
            'reply'  => "🛠️ *Staff Commands*\n• *release <pickup_code>* — confirm bale dispatch\n• *expense <amount> <category> <desc>* — log expense\n• *sale <phone> <category> <qty>* — create sale\n• *stock* — view warehouse inventory",
        ];
    }
}

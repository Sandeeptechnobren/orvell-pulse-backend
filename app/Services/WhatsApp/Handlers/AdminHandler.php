<?php

namespace App\Services\WhatsApp\Handlers;

use App\Models\Item_category;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\WhatsappConversation;
use App\Services\InventoryService;
use App\Services\EodReconciliationService;
use App\Services\InvoiceAmendmentService;
use App\Services\AuditService;
use App\Services\AgentPromptService;

class AdminHandler
{
    protected InventoryService $inventoryService;
    protected EodReconciliationService $eodService;
    protected InvoiceAmendmentService $amendmentService;
    protected AuditService $auditService;
    protected AgentPromptService $promptService;
    protected int $lowStockThreshold = 5;
    protected string $defaultCurrency = 'GHS';

    public function __construct(
        InventoryService $inventoryService,
        EodReconciliationService $eodService,
        InvoiceAmendmentService $amendmentService,
        AuditService $auditService,
        AgentPromptService $promptService
    ) {
        $this->inventoryService = $inventoryService;
        $this->eodService = $eodService;
        $this->amendmentService = $amendmentService;
        $this->auditService = $auditService;
        $this->promptService = $promptService;
    }

    public function handle(string $from, string $t, WhatsappConversation $conv, ?int $adminId = null): array
    {
        $lc = mb_strtolower(trim($t));

        // 1. Stock / Inventory report
        if (preg_match('/\b(stock|inventory|stocks|catalogue|catalog|bales)\b/i', $lc) && !str_contains($lc, 'low')) {
            $categories = Item_category::all();
            if ($categories->isEmpty()) {
                return ['action' => 'admin_command', 'reply' => 'No categories or inventory configured.'];
            }

            $lines = [];
            foreach ($categories as $cat) {
                $breakdown = $this->inventoryService->getStockBreakdown($cat->id);
                $lines[] = "• *{$cat->category_name}*\n  Available: {$breakdown['available_stock']} | Reserved: {$breakdown['reserved_stock']} | Total: {$breakdown['total_on_hand']}";
            }

            $report = "📦 *Live Warehouse Stock Report*\n\n" . implode("\n\n", $lines);
            return ['action' => 'admin_command', 'reply' => $report];
        }

        // 2. Low stock alert
        if (str_contains($lc, 'low stock') || str_contains($lc, 'low-stock')) {
            $categories = Item_category::all();
            $low = [];
            foreach ($categories as $cat) {
                $breakdown = $this->inventoryService->getStockBreakdown($cat->id);
                if ($breakdown['available_stock'] <= $this->lowStockThreshold) {
                    $low[] = "• *{$cat->category_name}*: {$breakdown['available_stock']} available ⚠️";
                }
            }

            if (empty($low)) {
                return ['action' => 'admin_command', 'reply' => "✅ All categories have healthy stock levels (> {$this->lowStockThreshold})."];
            }

            return ['action' => 'admin_command', 'reply' => "⚠️ *Low Stock Alert*\n" . implode("\n", $low)];
        }

        // 3. Pending orders
        if (str_contains($lc, 'pending') && str_contains($lc, 'order')) {
            $pending = Order::where('payment_status', 'pending')
                ->latest()
                ->take(10)
                ->get();

            if ($pending->isEmpty()) {
                return ['action' => 'admin_command', 'reply' => '🎉 No pending unpaid orders.'];
            }

            $lines = $pending->map(function ($o) {
                return "• Order #{$o->order_no} ({$o->buyer?->name}) — {$this->defaultCurrency} " . number_format((float) $o->total_amount, 2);
            })->implode("\n");

            return ['action' => 'admin_command', 'reply' => "🕓 *Pending Orders*\n{$lines}"];
        }

        // 4. Today's Sales
        if (str_contains($lc, 'sales') || str_contains($lc, 'today')) {
            $today = now()->toDateString();
            $paid = (float) Order::whereDate('created_at', $today)
                ->where('payment_status', 'paid')
                ->sum('total_amount');

            $count = Order::whereDate('created_at', $today)
                ->count();

            return ['action' => 'admin_command', 'reply' => "💰 *Today's Sales Summary ({$today})*\nPaid Sales: {$this->defaultCurrency} " . number_format($paid, 2) . "\nTotal Orders: {$count}"];
        }

        // 5. Generate EOD Reconciliation: "eod" or "eod <date>"
        if (preg_match('/^eod(?:\s+(\d{4}-\d{2}-\d{2}))?$/i', $t, $m)) {
            $date = $m[1] ?? now()->toDateString();
            try {
                $recon = $this->eodService->generateDailyReconciliation($date, $adminId ?? 1, $companyId, true);

                return [
                    'action' => 'admin_eod',
                    'reply'  => "📊 *EOD Reconciliation Generated ({$date})*\n\n" .
                        "• Total Sales: {$this->defaultCurrency} " . number_format($recon['total_sales_amount'], 2) . "\n" .
                        "• Cash Collected: {$this->defaultCurrency} " . number_format($recon['total_cash_collected'], 2) . "\n" .
                        "• Bank Deposits: {$this->defaultCurrency} " . number_format($recon['total_bank_deposits'], 2) . "\n" .
                        "• Expenses: {$this->defaultCurrency} " . number_format($recon['total_expenses'], 2) . "\n" .
                        "• Net Cash in Hand: {$this->defaultCurrency} " . number_format($recon['net_cash_balance'], 2) . "\n" .
                        "• Stock Variance: {$recon['stock_variance_units']} units\n" .
                        "• Status: *Reconciled & Saved*",
                ];
            } catch (\Throwable $e) {
                return ['action' => 'error', 'reply' => "❌ EOD reconciliation failed: " . $e->getMessage()];
            }
        }

        // 6. Approve Invoice Amendment: "approve amendment <id>"
        if (preg_match('/^approve\s+amendment\s+(\d+)$/i', $t, $m)) {
            $requestId = (int) $m[1];
            try {
                $amended = $this->amendmentService->approveAmendment($requestId, $adminId ?? 1, $companyId);
                return [
                    'action' => 'amendment_approved',
                    'reply'  => "✅ *Amendment Approved!*\nNew Versioned Invoice: #{$amended->invoice_number}\nTotal: {$this->defaultCurrency} " . number_format((float) $amended->total_amount, 2),
                ];
            } catch (\Throwable $e) {
                return ['action' => 'error', 'reply' => "❌ Amendment approval failed: " . $e->getMessage()];
            }
        }

        // 7. Reject Invoice Amendment: "reject amendment <id> <reason>"
        if (preg_match('/^reject\s+amendment\s+(\d+)(?:\s+(.+))?$/i', $t, $m)) {
            $requestId = (int) $m[1];
            $reason = $m[2] ?? 'Rejected via Admin WhatsApp';
            try {
                $rejected = $this->amendmentService->rejectAmendment($requestId, $reason, $adminId ?? 1, $companyId);
                return [
                    'action' => 'amendment_rejected',
                    'reply'  => "🚫 *Amendment Request #{$requestId} Rejected*\nReason: {$reason}",
                ];
            } catch (\Throwable $e) {
                return ['action' => 'error', 'reply' => "❌ Amendment rejection failed: " . $e->getMessage()];
            }
        }

        // // Admin Help
        // return [
        //     'action' => 'admin_help',
        //     'reply'  => "👑 *Admin Commands*\n• *stock* — live inventory breakdown\n• *low stock* — low stock alerts\n• *sales* — today's revenue summary\n• *pending orders* — unpaid orders\n• *eod [date]* — generate EOD report\n• *approve amendment <id>* — approve invoice revision\n• *reject amendment <id> [reason]*",
        // ];
        // 8. Conversational & Custom IQ Prompt Delegation
        return $this->promptService->handleConversationalMessage('admin', $from, $t, $companyId);
    }
}

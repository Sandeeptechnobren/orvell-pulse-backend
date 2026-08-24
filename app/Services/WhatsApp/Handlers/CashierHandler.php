<?php

namespace App\Services\WhatsApp\Handlers;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\BankDeposit;
use App\Models\WhatsappConversation;
use App\Services\PaymentService;
use App\Services\BankDepositService;
use App\Services\EodReconciliationService;
use App\Services\AuditService;

class CashierHandler
{
    protected PaymentService $paymentService;
    protected BankDepositService $depositService;
    protected EodReconciliationService $eodService;
    protected AuditService $auditService;
    protected string $defaultCurrency = 'GHS';

    public function __construct(
        PaymentService $paymentService,
        BankDepositService $depositService,
        EodReconciliationService $eodService,
        AuditService $auditService
    ) {
        $this->paymentService = $paymentService;
        $this->depositService = $depositService;
        $this->eodService = $eodService;
        $this->auditService = $auditService;
    }

    public function handle(string $from, string $t, int $companyId, WhatsappConversation $conv, ?int $cashierId = null): array
    {
        $lc = mb_strtolower(trim($t));

        // 1. Record Cash Payment: "cash <invoice_or_order_no> <amount>"
        if (preg_match('/^cash\s+([a-zA-Z0-9_-]+)\s+([\d.]+)(?:\s+(.+))?$/i', $t, $m)) {
            $identifier = trim($m[1]);
            $amount = (float) $m[2];
            $notes = isset($m[3]) ? trim($m[3]) : 'Cashier WhatsApp counter payment';

            // Find Invoice by invoice_number or Order by order_no
            $invoice = Invoice::where('invoice_number', $identifier)
                ->where('company_id', $companyId)
                ->first();

            if (!$invoice) {
                $order = Order::where('order_no', $identifier)
                    ->where('company_id', $companyId)
                    ->first();
                $invoice = $order?->invoice;
            }

            if (!$invoice) {
                return [
                    'action' => 'error',
                    'reply'  => "❌ Invoice or Order \"{$identifier}\" not found.",
                ];
            }

            try {
                $paymentResult = $this->paymentService->recordCashPayment([
                    'invoice_id' => $invoice->id,
                    'amount'     => $amount,
                    'notes'      => $notes,
                ], $cashierId ?? 1, $companyId);

                $payment = $paymentResult['payment'];
                $status = $paymentResult['payment_status'];
                $balance = $paymentResult['outstanding_balance'];

                return [
                    'action' => 'cash_recorded',
                    'reply'  => "💵 *Cash Payment Recorded!*\nPayment Ref: #{$payment->payment_number}\nInvoice: #{$invoice->invoice_number}\nAmount Received: {$this->defaultCurrency} " . number_format($amount, 2) . "\nInvoice Status: *{$status}*\nOutstanding: {$this->defaultCurrency} " . number_format($balance, 2),
                ];
            } catch (\Throwable $e) {
                return [
                    'action' => 'error',
                    'reply'  => "❌ Cash recording failed: " . $e->getMessage(),
                ];
            }
        }

        // 2. Record Bank Deposit: "deposit <amount> <bank_name> <reference>"
        if (preg_match('/^deposit\s+([\d.]+)\s+([a-zA-Z0-9\s_-]+?)\s+([a-zA-Z0-9_-]+)$/i', $t, $m)) {
            $amount = (float) $m[1];
            $bankName = trim($m[2]);
            $referenceNumber = trim($m[3]);

            try {
                $deposit = $this->depositService->recordDeposit([
                    'company_id'       => $companyId,
                    'amount'           => $amount,
                    'bank_name'        => $bankName,
                    'reference_number' => $referenceNumber,
                    'notes'            => 'Cashier WhatsApp bank deposit',
                ], $cashierId ?? 1, $companyId);

                return [
                    'action' => 'deposit_recorded',
                    'reply'  => "🏦 *Bank Deposit Recorded!*\nRef: #{$deposit->deposit_number}\nBank: {$deposit->bank_name}\nAmount: {$this->defaultCurrency} " . number_format($deposit->amount, 2) . "\nBank Ref: {$deposit->reference_number}",
                ];
            } catch (\Throwable $e) {
                return [
                    'action' => 'error',
                    'reply'  => "❌ Bank deposit recording failed: " . $e->getMessage(),
                ];
            }
        }

        // 3. Cash & Till Summary: "till" or "cash summary"
        if (str_contains($lc, 'till') || str_contains($lc, 'cash summary') || str_contains($lc, 'drawer')) {
            $today = now()->toDateString();
            try {
                $recon = $this->eodService->generateDailyReconciliation($today, $cashierId ?? 1, $companyId, false);

                return [
                    'action' => 'cash_till_summary',
                    'reply'  => "💰 *Cashier Daily Till Summary ({$today})*\n\n" .
                        "• Total Cash Collected: {$this->defaultCurrency} " . number_format($recon['total_cash_collected'], 2) . "\n" .
                        "• Bank Deposits: {$this->defaultCurrency} " . number_format($recon['total_bank_deposits'], 2) . "\n" .
                        "• Operational Expenses: {$this->defaultCurrency} " . number_format($recon['total_expenses'], 2) . "\n" .
                        "────────────────────\n" .
                        "💵 *Net Cash in Drawer:* {$this->defaultCurrency} " . number_format($recon['net_cash_balance'], 2) . "\n" .
                        "🕓 Pending Invoices: {$recon['pending_invoices_count']} ({$this->defaultCurrency} " . number_format($recon['pending_invoices_amount'], 2) . ")",
                ];
            } catch (\Throwable $e) {
                return [
                    'action' => 'error',
                    'reply'  => "❌ Till summary calculation failed: " . $e->getMessage(),
                ];
            }
        }

        // Cashier Help
        return [
            'action' => 'cashier_help',
            'reply'  => "💼 *Cashier Commands*\n• *cash <invoice/order_no> <amount>* — record cash receipt\n• *deposit <amount> <bank> <ref>* — log bank deposit\n• *till* — view current drawer cash position",
        ];
    }
}

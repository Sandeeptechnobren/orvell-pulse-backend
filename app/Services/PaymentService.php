<?php

namespace App\Services;

use App\Jobs\SendPaymentNotification;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        protected AuditService $auditService
    ) {
    }

    /**
     * Cashier confirms a cash payment against an invoice. Updates paid_amount,
     * payment_status (unpaid -> partial -> paid) and syncs the linked order.
     *
     * Returns ['payment', 'invoice', 'outstanding_balance', 'payment_status'].
     */
    public function recordCashPayment(array $data, ?int $cashierId = null, ?int $companyId = null): array
    {
        $result = DB::transaction(function () use ($data, $cashierId, $companyId) {
            $invoice = $this->resolveInvoice($data);

            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'invoice' => ["Invoice {$invoice->invoice_number} is cancelled and cannot accept payments."],
                ]);
            }

            $amount = round((float) $data['amount'], 2);
            $outstanding = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);

            if ($amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => ["Amount GHS {$amount} exceeds the outstanding balance of GHS {$outstanding} on {$invoice->invoice_number}."],
                ]);
            }

            $payment = Payment::create([
                'invoice_id'          => $invoice->id,
                'order_id'            => $invoice->order_id,
                'company_id'          => $companyId,
                'amount'              => $amount,
                'currency'            => 'GHS',
                'status'              => 'verified',
                'payment_method'      => 'cash',
                'payment_reference'   => $data['payment_reference'] ?? null,
                'payment_proof_image' => $data['payment_proof_image'] ?? null,
                'cashier_id'          => $cashierId,
                'notes'               => $data['notes'] ?? null,
            ]);

            $result = $this->applyPaymentToInvoice($invoice, $amount);

            $this->auditService->log(
                action: 'payment.cash_recorded',
                auditable: $payment,
                newValues: [
                    'payment_number' => $payment->payment_number,
                    'invoice_number' => $invoice->invoice_number,
                    'amount'         => $amount,
                    'payment_status' => $result['payment_status'],
                ],
                companyId: $companyId,
                userId: $cashierId
            );

            return [
                'payment'             => $payment,
                'invoice'             => $invoice->fresh(),
                'outstanding_balance' => $result['outstanding_balance'],
                'payment_status'      => $result['payment_status'],
            ];
        });

        // Receipt text + PDF go out via the queue - the API never waits on WhatsApp.
        SendPaymentNotification::dispatch($result['payment']->id);

        return $result;
    }

    /**
     * Verify a Paystack transaction reference and record it as a payment.
     * The transaction's metadata must carry the invoice_number it pays.
     */
    public function verifyAndRecordPaystackPayment(string $reference, ?int $companyId = null): array
    {
        $secret = config('services.paystack.secret', env('PAYSTACK_SECRET_KEY'));

        if (!$secret) {
            throw ValidationException::withMessages([
                'paystack' => ['Paystack is not configured (missing secret key).'],
            ]);
        }

        $response = Http::withToken($secret)
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        $body = $response->json();

        if (!$response->ok() || !($body['status'] ?? false) || (($body['data']['status'] ?? '') !== 'success')) {
            throw ValidationException::withMessages([
                'reference' => ['Paystack transaction could not be verified or was not successful.'],
            ]);
        }

        $txData = $body['data'];
        $amount = round(((int) $txData['amount']) / 100, 2); // Paystack amounts are in pesewas
        $invoiceNumber = $txData['metadata']['invoice_number'] ?? null;

        if (!$invoiceNumber) {
            throw ValidationException::withMessages([
                'reference' => ['The transaction has no invoice_number in its metadata; it cannot be matched to an invoice.'],
            ]);
        }

        $result = DB::transaction(function () use ($invoiceNumber, $reference, $amount, $txData, $companyId) {
            // Idempotency: the same reference must not be recorded twice.
            $existing = Payment::where('payment_reference', $reference)->first();
            if ($existing) {
                $invoice = $existing->invoice;
                return [
                    'payment'             => $existing,
                    'invoice'             => $invoice,
                    'outstanding_balance' => round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2),
                    'payment_status'      => $invoice->payment_status,
                ];
            }

            $invoice = Invoice::where('invoice_number', $invoiceNumber)->lockForUpdate()->first();
            if (!$invoice) {
                throw ValidationException::withMessages([
                    'reference' => ["Invoice {$invoiceNumber} referenced by the transaction was not found."],
                ]);
            }

            $payment = Payment::create([
                'invoice_id'        => $invoice->id,
                'order_id'          => $invoice->order_id,
                'company_id'        => $companyId,
                'amount'            => $amount,
                'currency'          => $txData['currency'] ?? 'GHS',
                'status'            => 'verified',
                'payment_method'    => 'paystack',
                'payment_reference' => $reference,
                'customer_email'    => $txData['customer']['email'] ?? null,
                'response'          => $txData,
            ]);

            $result = $this->applyPaymentToInvoice($invoice, $amount);

            $this->auditService->log(
                action: 'payment.paystack_verified',
                auditable: $payment,
                newValues: [
                    'payment_number' => $payment->payment_number,
                    'invoice_number' => $invoice->invoice_number,
                    'amount'         => $amount,
                    'reference'      => $reference,
                ],
                companyId: $companyId
            );

            return [
                'payment'             => $payment,
                'invoice'             => $invoice->fresh(),
                'outstanding_balance' => $result['outstanding_balance'],
                'payment_status'      => $result['payment_status'],
            ];
        });

        // Receipt text + PDF go out via the queue - the API never waits on WhatsApp.
        SendPaymentNotification::dispatch($result['payment']->id);

        return $result;
    }

    /**
     * Apply an amount to the invoice's paid_amount and derive statuses, then
     * sync the linked order's payment_status.
     */
    private function applyPaymentToInvoice(Invoice $invoice, float $amount): array
    {
        $newPaid = round((float) $invoice->paid_amount + $amount, 2);
        $outstanding = round((float) $invoice->total_amount - $newPaid, 2);
        $paymentStatus = $outstanding <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');

        $invoice->update([
            'paid_amount'    => $newPaid,
            'payment_status' => $paymentStatus,
        ]);

        if ($invoice->order_id && $invoice->order) {
            $invoice->order->update([
                'payment_status' => $paymentStatus === 'paid' ? 'paid' : 'pending',
            ]);
        }

        return [
            'outstanding_balance' => max($outstanding, 0),
            'payment_status'      => $paymentStatus,
        ];
    }

    private function resolveInvoice(array $data): Invoice
    {
        $query = Invoice::query()->lockForUpdate();

        if (!empty($data['invoice_id'])) {
            $query->where('id', $data['invoice_id']);
        } elseif (!empty($data['invoice_number'])) {
            $query->where('invoice_number', $data['invoice_number']);
        } elseif (!empty($data['order_id'])) {
            $query->where('order_id', $data['order_id']);
        } else {
            throw ValidationException::withMessages([
                'invoice' => ['Provide invoice_id, invoice_number, or order_id to identify the invoice.'],
            ]);
        }

        $invoice = $query->first();

        if (!$invoice) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice not found.'],
            ]);
        }

        return $invoice;
    }
}

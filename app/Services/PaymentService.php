<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Buyer;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PaystackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    protected AuditService $auditService;
    protected PaystackService $paystackService;

    public function __construct(AuditService $auditService, PaystackService $paystackService)
    {
        $this->auditService = $auditService;
        $this->paystackService = $paystackService;
    }

    /**
     * Record a cash payment from a Cashier against an Invoice with concurrency locking and overpayment prevention.
     * Guaranteed transaction-safe; finalized invoice financial totals remain strictly IMMUTABLE.
     *
     * @param array $data
     * @param int|null $cashierId
     * @param int|null $companyId
     * @return array
     * @throws ValidationException
     */
    public function recordCashPayment(array $data, ?int $cashierId = null, ?int $companyId = null): array
    {
        return DB::transaction(function () use ($data, $cashierId, $companyId) {
            $invoiceId = $data['invoice_id'] ?? null;
            $invoiceNumber = $data['invoice_number'] ?? null;
            $orderId = $data['order_id'] ?? null;
            $amount = round((float) ($data['amount'] ?? 0.00), 2);

            if ($amount <= 0.00) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment amount must be greater than zero.'],
                ]);
            }

            // Lock the invoice row for update
            $query = Invoice::query()->lockForUpdate();

            if ($invoiceId) {
                $query->where('id', $invoiceId);
            } elseif ($invoiceNumber) {
                $query->where('invoice_number', $invoiceNumber);
            } elseif ($orderId) {
                $query->where('order_id', $orderId);
            } else {
                throw ValidationException::withMessages([
                    'invoice' => ['Invoice ID, invoice number, or order ID is required.'],
                ]);
            }

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $invoice = $query->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice' => ['Invoice not found or unauthorized for this company.'],
                ]);
            }

            // Calculate existing verified payments
            $currentPaid = (float) Payment::where('invoice_id', $invoice->id)
                ->where('status', 'verified')
                ->sum('amount');

            $outstandingBalance = round((float) $invoice->total_amount - $currentPaid, 2);

            if ($outstandingBalance <= 0.00) {
                throw ValidationException::withMessages([
                    'amount' => ["Invoice {$invoice->invoice_number} is already fully paid."],
                ]);
            }

            // Overpayment prevention
            if ($amount > $outstandingBalance) {
                throw ValidationException::withMessages([
                    'amount' => ["Payment amount of {$amount} exceeds remaining outstanding balance of {$outstandingBalance}."],
                ]);
            }

            $authUser = auth()->user();
            $effectiveCashierId = $cashierId ?? ($authUser instanceof User ? $authUser->id : null);

            $paymentRef = $data['payment_reference'] ?? ('CASH-' . strtoupper(Str::random(10)));

            // 1. Create Payment record
            $payment = Payment::create([
                'invoice_id'          => $invoice->id,
                'order_id'            => $invoice->order_id,
                'buyer_id'            => $invoice->buyer_id,
                'company_id'          => $invoice->company_id,
                'client_id'           => $invoice->client_id,
                'cashier_id'          => $effectiveCashierId,
                'amount'              => $amount,
                'currency'            => $invoice->order?->currency ?? 'GHS',
                'payment_method'      => 'cash',
                'payment_reference'   => $paymentRef,
                'payment_proof_image' => $data['payment_proof_image'] ?? null,
                'status'              => 'verified',
                'notes'               => $data['notes'] ?? 'Cash payment received at warehouse counter',
            ]);

            // 2. Calculate new payment status (unpaid -> partial -> paid)
            $newPaidTotal = round($currentPaid + $amount, 2);
            $newPaymentStatus = ($newPaidTotal >= (float) $invoice->total_amount) ? 'paid' : 'partial';

            // 3. Update Invoice payment state (WITHOUT modifying total_amount, subtotal, or line items!)
            $invoice->update([
                'paid_amount'    => $newPaidTotal,
                'payment_status' => $newPaymentStatus,
            ]);

            // 4. Update Order payment state
            if ($invoice->order) {
                $invoice->order->update([
                    'payment_status'    => $newPaymentStatus,
                    'payment_method'    => 'cash',
                    'payment_reference' => $paymentRef,
                    'status'            => ($newPaymentStatus === 'paid' && $invoice->order->status === 'pending') ? 'processing' : $invoice->order->status,
                ]);
            }

            $newRemainingBalance = round((float) $invoice->total_amount - $newPaidTotal, 2);

            // 5. Audit log
            $this->auditService->log(
                action: 'payment.cash_recorded',
                auditable: $payment,
                newValues: [
                    'payment_number'      => $payment->payment_number,
                    'invoice_number'      => $invoice->invoice_number,
                    'amount'              => $amount,
                    'total_paid'          => $newPaidTotal,
                    'outstanding_balance' => $newRemainingBalance,
                    'payment_status'      => $newPaymentStatus,
                ],
                companyId: $invoice->company_id,
                userId: $effectiveCashierId
            );

            return [
                'payment'             => $payment,
                'invoice'             => $invoice->fresh(),
                'order'               => $invoice->order?->fresh(),
                'outstanding_balance' => $newRemainingBalance,
                'payment_status'      => $newPaymentStatus,
            ];
        });
    }

    /**
     * Verify an online Paystack transaction and record settlement against the matching invoice/order.
     *
     * @param string $reference
     * @param int|null $companyId
     * @return array
     * @throws ValidationException
     */
    public function verifyAndRecordPaystackPayment(string $reference, ?int $companyId = null): array
    {
        // Check if already processed
        $existingPayment = Payment::where('payment_reference', $reference)->first();
        if ($existingPayment) {
            return [
                'idempotent'     => true,
                'payment'        => $existingPayment,
                'invoice'        => $existingPayment->invoice,
                'order'          => $existingPayment->order,
                'payment_status' => $existingPayment->invoice?->payment_status ?? 'paid',
            ];
        }

        // Server-side verification via Paystack API
        $verifyData = $this->paystackService->verify($reference);

        if (!$verifyData || ($verifyData['status'] ?? '') !== 'success') {
            throw ValidationException::withMessages([
                'paystack' => ["Paystack verification failed for reference {$reference}."],
            ]);
        }

        return $this->recordVerifiedPaymentData($reference, $verifyData, $companyId);
    }

    /**
     * Record verified payment settlement payload inside DB transaction with exclusive lock.
     *
     * @param string $reference
     * @param array $paymentData
     * @param int|null $companyId
     * @return array
     * @throws ValidationException
     */
    public function recordVerifiedPaymentData(string $reference, array $paymentData, ?int $companyId = null): array
    {
        return DB::transaction(function () use ($reference, $paymentData, $companyId) {
            $existingPayment = Payment::where('payment_reference', $reference)->first();
            if ($existingPayment) {
                return [
                    'idempotent'     => true,
                    'payment'        => $existingPayment,
                    'invoice'        => $existingPayment->invoice,
                    'order'          => $existingPayment->order,
                    'payment_status' => $existingPayment->invoice?->payment_status ?? 'paid',
                ];
            }

            $rawAmount = (float) ($paymentData['amount'] ?? 0);
            $amountPaid = ($rawAmount > 1000) ? round($rawAmount / 100, 2) : round($rawAmount, 2);
            $customerEmail = $paymentData['customer']['email'] ?? null;
            $metadata = $paymentData['metadata'] ?? [];

            $orderId = $metadata['order_id'] ?? null;
            $invoiceId = $metadata['invoice_id'] ?? null;

            $query = Invoice::query()->lockForUpdate();

            if ($invoiceId) {
                $query->where('id', $invoiceId);
            } elseif ($orderId) {
                $query->where('order_id', $orderId);
            } else {
                $matchedOrder = Order::where('payment_reference', $reference)->first();
                if ($matchedOrder) {
                    $query->where('order_id', $matchedOrder->id);
                }
            }

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $invoice = $query->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice' => ["No matching invoice found for reference {$reference}."],
                ]);
            }

            $currentPaid = (float) Payment::where('invoice_id', $invoice->id)
                ->where('status', 'verified')
                ->sum('amount');

            // Record verified online payment
            $payment = Payment::create([
                'invoice_id'        => $invoice->id,
                'order_id'          => $invoice->order_id,
                'buyer_id'          => $invoice->buyer_id,
                'company_id'        => $invoice->company_id,
                'client_id'         => $invoice->client_id,
                'customer_email'    => $customerEmail,
                'amount'            => $amountPaid,
                'currency'          => $paymentData['currency'] ?? 'GHS',
                'payment_method'    => 'paystack',
                'payment_reference' => $reference,
                'status'            => 'verified',
                'response'          => $paymentData,
                'notes'             => 'Verified Paystack online payment',
            ]);

            $newPaidTotal = round($currentPaid + $amountPaid, 2);
            $newPaymentStatus = ($newPaidTotal >= (float) $invoice->total_amount) ? 'paid' : 'partial';

            $invoice->update([
                'paid_amount'    => $newPaidTotal,
                'payment_status' => $newPaymentStatus,
            ]);

            if ($invoice->order) {
                $invoice->order->update([
                    'payment_status'    => $newPaymentStatus,
                    'payment_method'    => 'paystack',
                    'payment_reference' => $reference,
                    'status'            => ($newPaymentStatus === 'paid' && $invoice->order->status === 'pending') ? 'processing' : $invoice->order->status,
                ]);
            }

            $this->auditService->log(
                action: 'payment.paystack_verified',
                auditable: $payment,
                newValues: [
                    'payment_number' => $payment->payment_number,
                    'reference'      => $reference,
                    'amount'         => $amountPaid,
                    'payment_status' => $newPaymentStatus,
                ],
                companyId: $invoice->company_id
            );

            return [
                'idempotent'          => false,
                'payment'             => $payment,
                'invoice'             => $invoice->fresh(),
                'order'               => $invoice->order?->fresh(),
                'outstanding_balance' => round((float) $invoice->total_amount - $newPaidTotal, 2),
                'payment_status'      => $newPaymentStatus,
            ];
        });
    }

    /**
     * Process Paystack Webhook payload with HMAC signature validation and duplicate event protection.
     *
     * @param string $rawPayload
     * @param string|null $signature
     * @param array $payloadData
     * @return array
     * @throws ValidationException
     */
    public function handlePaystackWebhook(string $rawPayload, ?string $signature, array $payloadData): array
    {
        $secret = (string) config('paystack.secret_key');

        if ($secret !== '') {
            $computed = hash_hmac('sha512', $rawPayload, $secret);
            if (empty($signature) || !hash_equals($computed, (string) $signature)) {
                $this->auditService->log(
                    action: 'payment.webhook_rejected_signature',
                    auditable: 'PaystackWebhook',
                    newValues: ['reason' => 'Invalid HMAC SHA512 signature']
                );
                throw ValidationException::withMessages([
                    'signature' => ['Invalid Paystack webhook signature.'],
                ]);
            }
        }

        $event = $payloadData['event'] ?? '';
        $data = $payloadData['data'] ?? [];
        $reference = $data['reference'] ?? null;

        if ($event === 'charge.success' && $reference) {
            return $this->recordVerifiedPaymentData($reference, $data);
        }

        return [
            'status'  => 'ignored',
            'event'   => $event,
            'message' => 'Event does not require financial state transition',
        ];
    }

    /**
     * Retrieve current payment status and financial summary for an invoice.
     */
    public function getInvoicePaymentSummary(int $invoiceId, ?int $companyId = null): array
    {
        $query = Invoice::where('id', $invoiceId)->with(['payments', 'order', 'buyer']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $invoice = $query->first();

        if (!$invoice) {
            throw ValidationException::withMessages([
                'invoice_id' => ['Invoice not found or unauthorized.'],
            ]);
        }

        $totalPaid = (float) Payment::where('invoice_id', $invoice->id)
            ->where('status', 'verified')
            ->sum('amount');

        $grandTotal = (float) $invoice->total_amount;
        $outstanding = round(max(0.0, $grandTotal - $totalPaid), 2);

        $status = 'unpaid';
        if ($totalPaid >= $grandTotal) {
            $status = 'paid';
        } elseif ($totalPaid > 0.0) {
            $status = 'partial';
        }

        return [
            'invoice_id'          => $invoice->id,
            'invoice_number'      => $invoice->invoice_number,
            'grand_total'         => $grandTotal,
            'total_paid'          => $totalPaid,
            'outstanding_balance' => $outstanding,
            'payment_status'      => $status,
            'payments_count'      => $invoice->payments->count(),
            'payments'            => $invoice->payments,
        ];
    }
}

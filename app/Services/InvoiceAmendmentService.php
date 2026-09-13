<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceAmendmentRequest;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceAmendmentService
{
    public function __construct(
        protected AuditService $auditService
    ) {
    }

    /**
     * Staff submit a change request against a finalized invoice. Nothing on
     * the invoice changes until an admin/manager approves.
     */
    public function requestAmendment(
        int $invoiceId,
        array $data,
        ?int $requestedById = null,
        ?int $companyId = null
    ): InvoiceAmendmentRequest {
        return DB::transaction(function () use ($invoiceId, $data, $requestedById, $companyId) {
            $invoice = Invoice::where('id', $invoiceId)->lockForUpdate()->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['Invoice not found.'],
                ]);
            }

            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'invoice_id' => ["Cancelled invoice {$invoice->invoice_number} cannot be amended."],
                ]);
            }

            if ($invoice->status === 'amended') {
                throw ValidationException::withMessages([
                    'invoice_id' => ["Invoice {$invoice->invoice_number} was already amended. Request the amendment against its latest version."],
                ]);
            }

            $pendingExists = InvoiceAmendmentRequest::where('invoice_id', $invoice->id)
                ->where('status', 'pending')
                ->exists();

            if ($pendingExists) {
                throw ValidationException::withMessages([
                    'invoice_id' => ["Invoice {$invoice->invoice_number} already has a pending amendment request."],
                ]);
            }

            $amendmentRequest = InvoiceAmendmentRequest::create([
                'invoice_id'        => $invoice->id,
                'requested_by'      => $requestedById,
                'reason'            => $data['reason'],
                'requested_changes' => $data['requested_changes'],
                'status'            => 'pending',
            ]);

            $this->auditService->log(
                action: 'invoice.amendment_requested',
                auditable: $amendmentRequest,
                newValues: [
                    'invoice_number' => $invoice->invoice_number,
                    'reason'         => $data['reason'],
                    'changes'        => $data['requested_changes'],
                ],
                companyId: $companyId,
                userId: $requestedById
            );

            return $amendmentRequest->load('invoice');
        });
    }

    /**
     * Admin/manager approves: the original invoice is marked 'amended' (kept
     * forever for the audit trail) and a new version INV-XXXX-A/B/C... is
     * issued carrying the requested changes and the already-paid amount.
     */
    public function approveAmendment(int $requestId, ?int $reviewerId = null, ?int $companyId = null): Invoice
    {
        return DB::transaction(function () use ($requestId, $reviewerId, $companyId) {
            $amendmentRequest = InvoiceAmendmentRequest::where('id', $requestId)
                ->lockForUpdate()
                ->first();

            if (!$amendmentRequest) {
                throw ValidationException::withMessages([
                    'amendment' => ['Amendment request not found.'],
                ]);
            }

            if ($amendmentRequest->status !== 'pending') {
                throw ValidationException::withMessages([
                    'amendment' => ["This amendment request is already '{$amendmentRequest->status}'."],
                ]);
            }

            $original = Invoice::where('id', $amendmentRequest->invoice_id)
                ->with('items')
                ->lockForUpdate()
                ->first();

            if (!$original) {
                throw ValidationException::withMessages([
                    'amendment' => ['The invoice under amendment no longer exists.'],
                ]);
            }

            $changes = $amendmentRequest->requested_changes ?? [];

            // Amendment chain is rooted at the first invoice: INV-0001 -> -A -> -B ...
            $rootId = $original->original_invoice_id ?? $original->id;
            $rootNumber = preg_replace('/-[A-Z]$/', '', $original->invoice_number);
            $priorVersions = Invoice::where('original_invoice_id', $rootId)->count();
            $version = chr(ord('A') + $priorVersions); // A, B, C...

            // Rebuild line items: replaced wholesale when the request carries
            // items, otherwise copied from the original.
            $newItems = [];
            $subtotal = 0.00;
            $sourceItems = !empty($changes['items'])
                ? $changes['items']
                : $original->items->toArray();

            foreach ($sourceItems as $item) {
                $qty = (int) ($item['quantity'] ?? 1);
                $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
                $lineTotal = round($qty * $unitPrice, 2);
                $subtotal = round($subtotal + $lineTotal, 2);

                $newItems[] = [
                    'bale_id'          => $item['bale_id'] ?? null,
                    'container_id'     => $item['container_id'] ?? null,
                    'item_category_id' => $item['item_category_id'] ?? null,
                    'item_description' => $item['item_description'] ?? 'Garment Bale',
                    'quantity'         => $qty,
                    'unit_price'       => $unitPrice,
                    'total_price'      => $lineTotal,
                ];
            }

            $taxAmount = round((float) ($changes['tax_amount'] ?? $original->tax_amount), 2);
            $discountAmount = round((float) ($changes['discount_amount'] ?? $original->discount_amount), 2);
            $totalAmount = round($subtotal + $taxAmount - $discountAmount, 2);
            $paidAmount = (float) $original->paid_amount;
            $outstanding = round($totalAmount - $paidAmount, 2);

            $newInvoice = Invoice::create([
                'invoice_number'      => "{$rootNumber}-{$version}",
                'original_invoice_id' => $rootId,
                'amendment_version'   => $version,
                'order_id'            => $original->order_id,
                'buyer_id'            => $original->buyer_id,
                'customer_id'         => $original->customer_id,
                'company_id'          => $original->company_id,
                'client_id'           => $original->client_id,
                'subtotal'            => $subtotal,
                'tax_amount'          => $taxAmount,
                'discount_amount'     => $discountAmount,
                'total_amount'        => $totalAmount,
                'paid_amount'         => $paidAmount,
                'payment_status'      => $outstanding <= 0 ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid'),
                'status'              => 'finalized',
                'notes'               => $changes['notes'] ?? $original->notes,
                'approved_by'         => $reviewerId,
            ]);

            foreach ($newItems as $line) {
                $line['invoice_id'] = $newInvoice->id;
                InvoiceItem::create($line);
            }

            // Original is retained, immutable, and marked amended.
            $original->update(['status' => 'amended']);

            // Keep the linked order's financials in sync with the live version.
            if ($original->order) {
                $original->order->update([
                    'invoice_code'   => $newInvoice->invoice_number,
                    'total_amount'   => $totalAmount,
                    'order_amount'   => $totalAmount,
                    'payment_status' => $newInvoice->payment_status === 'paid' ? 'paid' : 'pending',
                ]);
            }

            $amendmentRequest->update([
                'status'             => 'approved',
                'reviewed_by'        => $reviewerId,
                'reviewed_at'        => now(),
                'amended_invoice_id' => $newInvoice->id,
            ]);

            $this->auditService->log(
                action: 'invoice.amendment_approved',
                auditable: $newInvoice,
                oldValues: ['invoice_number' => $original->invoice_number, 'total_amount' => (float) $original->total_amount],
                newValues: ['invoice_number' => $newInvoice->invoice_number, 'total_amount' => $totalAmount],
                companyId: $companyId,
                userId: $reviewerId
            );

            return $newInvoice->load(['items', 'customer', 'order']);
        });
    }

    public function rejectAmendment(
        int $requestId,
        string $rejectionReason,
        ?int $reviewerId = null,
        ?int $companyId = null
    ): InvoiceAmendmentRequest {
        return DB::transaction(function () use ($requestId, $rejectionReason, $reviewerId, $companyId) {
            $amendmentRequest = InvoiceAmendmentRequest::where('id', $requestId)
                ->lockForUpdate()
                ->first();

            if (!$amendmentRequest) {
                throw ValidationException::withMessages([
                    'amendment' => ['Amendment request not found.'],
                ]);
            }

            if ($amendmentRequest->status !== 'pending') {
                throw ValidationException::withMessages([
                    'amendment' => ["This amendment request is already '{$amendmentRequest->status}'."],
                ]);
            }

            $amendmentRequest->update([
                'status'           => 'rejected',
                'reviewed_by'      => $reviewerId,
                'reviewed_at'      => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            $this->auditService->log(
                action: 'invoice.amendment_rejected',
                auditable: $amendmentRequest,
                newValues: ['rejection_reason' => $rejectionReason],
                companyId: $companyId,
                userId: $reviewerId
            );

            return $amendmentRequest->fresh(['invoice']);
        });
    }

    public function listAmendmentRequests(?string $status = null, ?int $companyId = null)
    {
        $query = InvoiceAmendmentRequest::with(['invoice', 'requester', 'reviewer', 'amendedInvoice'])
            ->latest('id');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate(25);
    }
}

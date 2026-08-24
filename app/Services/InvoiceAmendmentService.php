<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceAmendmentRequest;
use App\Models\Order;
use App\Models\Bale;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceAmendmentService
{
    protected AuditService $auditService;
    protected InventoryService $inventoryService;

    public function __construct(AuditService $auditService, InventoryService $inventoryService)
    {
        $this->auditService = $auditService;
        $this->inventoryService = $inventoryService;
    }

    /**
     * Request an invoice amendment for a finalized invoice with diff specifications.
     *
     * @param int $invoiceId
     * @param array $data
     * @param int $requestedById
     * @param int|null $companyId
     * @return InvoiceAmendmentRequest
     * @throws ValidationException
     */
    public function requestAmendment(int $invoiceId, array $data, int $requestedById, ?int $companyId = null): InvoiceAmendmentRequest
    {
        return DB::transaction(function () use ($invoiceId, $data, $requestedById, $companyId) {
            $query = Invoice::where('id', $invoiceId);

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $invoice = $query->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['Invoice not found or unauthorized for this company.'],
                ]);
            }

            if ($invoice->status !== 'finalized') {
                throw ValidationException::withMessages([
                    'invoice_id' => ["Only finalized invoices can be amended. Current status is '{$invoice->status}'."],
                ]);
            }

            // Check for existing pending request
            $hasPending = InvoiceAmendmentRequest::where('invoice_id', $invoice->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasPending) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['An amendment request is already pending review for this invoice.'],
                ]);
            }

            $reason = $data['reason'] ?? 'Price or item specification adjustment';
            $requestedChanges = $data['requested_changes'] ?? [];

            if (empty($requestedChanges)) {
                throw ValidationException::withMessages([
                    'requested_changes' => ['Requested changes payload is required.'],
                ]);
            }

            $amendmentRequest = InvoiceAmendmentRequest::create([
                'invoice_id'        => $invoice->id,
                'requested_by'      => $requestedById,
                'reason'            => $reason,
                'requested_changes' => $requestedChanges,
                'status'            => 'pending',
            ]);

            $this->auditService->log(
                action: 'invoice.amendment_requested',
                auditable: $amendmentRequest,
                newValues: [
                    'invoice_number'    => $invoice->invoice_number,
                    'reason'            => $reason,
                    'requested_changes' => $requestedChanges,
                ],
                companyId: $invoice->company_id,
                userId: $requestedById
            );

            return $amendmentRequest->load(['invoice', 'requester']);
        });
    }

    /**
     * Approve an invoice amendment: creates an immutable versioned invoice (#INV-XXXX-A),
     * reconciles physical inventory if items changed, and marks previous invoice amended.
     *
     * @param int $requestId
     * @param int $reviewerId
     * @param int|null $companyId
     * @return Invoice
     * @throws ValidationException
     */
    public function approveAmendment(int $requestId, int $reviewerId, ?int $companyId = null): Invoice
    {
        return DB::transaction(function () use ($requestId, $reviewerId, $companyId) {
            $query = InvoiceAmendmentRequest::where('id', $requestId)->lockForUpdate();

            $amendmentReq = $query->first();

            if (!$amendmentReq) {
                throw ValidationException::withMessages([
                    'request_id' => ['Amendment request not found.'],
                ]);
            }

            if ($amendmentReq->status !== 'pending') {
                throw ValidationException::withMessages([
                    'request_id' => ["Amendment request is already '{$amendmentReq->status}'."],
                ]);
            }

            $originalInvoice = Invoice::where('id', $amendmentReq->invoice_id)
                ->with(['items', 'order'])
                ->lockForUpdate()
                ->first();

            if (!$originalInvoice) {
                throw ValidationException::withMessages([
                    'invoice' => ['Associated invoice not found.'],
                ]);
            }

            if ($companyId && $originalInvoice->company_id != $companyId) {
                throw ValidationException::withMessages([
                    'company' => ['Unauthorized company context.'],
                ]);
            }

            $requestedChanges = $amendmentReq->requested_changes ?? [];

            // 1. Calculate new amendment version (A, B, C...)
            $baseInvoiceId = $originalInvoice->original_invoice_id ?? $originalInvoice->id;
            $rootInvoice = Invoice::find($baseInvoiceId) ?? $originalInvoice;
            
            $existingVersionsCount = Invoice::where(function ($q) use ($baseInvoiceId) {
                $q->where('id', $baseInvoiceId)->orWhere('original_invoice_id', $baseInvoiceId);
            })->count();

            $nextVersionLetter = chr(ord('A') + $existingVersionsCount - 1);
            $baseNumber = preg_replace('/-[A-Z]$/', '', $rootInvoice->invoice_number);
            $newInvoiceNumber = "{$baseNumber}-{$nextVersionLetter}";

            // 2. Resolve items diff and reconcile physical inventory
            $newItemsData = $requestedChanges['items'] ?? [];
            $finalLineSnapshots = [];
            $calculatedSubtotal = 0.00;

            if (!empty($newItemsData)) {
                // Determine removed bales (bales in original invoice not in newItems)
                $originalBaleIds = $originalInvoice->items->pluck('bale_id')->filter()->toArray();
                $newBaleIds = collect($newItemsData)->pluck('bale_id')->filter()->toArray();

                $removedBaleIds = array_diff($originalBaleIds, $newBaleIds);
                $addedBaleIds = array_diff($newBaleIds, $originalBaleIds);

                // Cancel reservation for removed bales
                foreach ($removedBaleIds as $removedBaleId) {
                    $bale = Bale::find($removedBaleId);
                    if ($bale && $bale->status === 'reserved') {
                        $this->inventoryService->cancelReservation($bale->id, $reviewerId, $originalInvoice->company_id);
                    }
                }

                // Reserve newly added bales
                foreach ($addedBaleIds as $addedBaleId) {
                    $bale = Bale::find($addedBaleId);
                    if ($bale && $bale->status === 'available') {
                        $this->inventoryService->reserveBale($bale->id, $originalInvoice->order_id, $reviewerId, $originalInvoice->company_id);
                    }
                }

                foreach ($newItemsData as $item) {
                    $qty = (int) ($item['quantity'] ?? 1);
                    $unitPrice = round((float) ($item['unit_price'] ?? 0.00), 2);
                    $lineTotal = round($qty * $unitPrice, 2);

                    $calculatedSubtotal = round($calculatedSubtotal + $lineTotal, 2);

                    $finalLineSnapshots[] = [
                        'bale_id'          => $item['bale_id'] ?? null,
                        'container_id'     => $item['container_id'] ?? null,
                        'product_id'       => $item['product_id'] ?? null,
                        'item_category_id' => $item['item_category_id'] ?? null,
                        'item_description' => $item['item_description'] ?? 'Garment Bale (Amended)',
                        'quantity'         => $qty,
                        'unit_price'       => $unitPrice,
                        'total_price'      => $lineTotal,
                    ];
                }
            } else {
                // Keep original line items if only financial fields (tax/discount) changed
                foreach ($originalInvoice->items as $origItem) {
                    $finalLineSnapshots[] = [
                        'bale_id'          => $origItem->bale_id,
                        'container_id'     => $origItem->container_id,
                        'product_id'       => $origItem->product_id,
                        'item_category_id' => $origItem->item_category_id,
                        'item_description' => $origItem->item_description,
                        'quantity'         => $origItem->quantity,
                        'unit_price'       => $origItem->unit_price,
                        'total_price'      => $origItem->total_price,
                    ];
                    $calculatedSubtotal = round($calculatedSubtotal + (float) $origItem->total_price, 2);
                }
            }

            $newTaxAmount = isset($requestedChanges['tax_amount'])
                ? round((float) $requestedChanges['tax_amount'], 2)
                : (float) $originalInvoice->tax_amount;

            $newDiscountAmount = isset($requestedChanges['discount_amount'])
                ? round((float) $requestedChanges['discount_amount'], 2)
                : (float) $originalInvoice->discount_amount;

            $newGrandTotal = round($calculatedSubtotal + $newTaxAmount - $newDiscountAmount, 2);

            // Carried-forward payment settlement calculation
            $carriedPaidAmount = (float) $originalInvoice->paid_amount;
            $newPaymentStatus = 'unpaid';
            if ($carriedPaidAmount >= $newGrandTotal) {
                $newPaymentStatus = 'paid';
            } elseif ($carriedPaidAmount > 0.00) {
                $newPaymentStatus = 'partial';
            }

            // 3. Create the NEW amended finalized invoice
            $amendedInvoice = Invoice::create([
                'invoice_number'      => $newInvoiceNumber,
                'original_invoice_id' => $baseInvoiceId,
                'amendment_version'   => $nextVersionLetter,
                'order_id'            => $originalInvoice->order_id,
                'buyer_id'            => $originalInvoice->buyer_id,
                'company_id'          => $originalInvoice->company_id,
                'client_id'           => $originalInvoice->client_id,
                'subtotal'            => $calculatedSubtotal,
                'tax_amount'          => $newTaxAmount,
                'discount_amount'     => $newDiscountAmount,
                'total_amount'        => $newGrandTotal,
                'paid_amount'         => $carriedPaidAmount,
                'payment_status'      => $newPaymentStatus,
                'status'              => 'finalized',
                'issued_date'         => $originalInvoice->issued_date,
                'due_date'            => $originalInvoice->due_date,
                'notes'               => "Amended version of {$originalInvoice->invoice_number}. Reason: {$amendmentReq->reason}",
                'created_by'          => $amendmentReq->requested_by,
                'approved_by'         => $reviewerId,
            ]);

            foreach ($finalLineSnapshots as $line) {
                $line['invoice_id'] = $amendedInvoice->id;
                InvoiceItem::create($line);
            }

            // 4. Mark previous invoice as 'amended'
            $originalInvoice->update([
                'status' => 'amended',
            ]);

            // 5. Update Order totals & invoice_code
            if ($originalInvoice->order) {
                $originalInvoice->order->update([
                    'invoice_code'   => $amendedInvoice->invoice_number,
                    'total_amount'   => $newGrandTotal,
                    'order_amount'   => $newGrandTotal,
                    'payment_status' => $newPaymentStatus,
                ]);
            }

            // 6. Update amendment request status
            $amendmentReq->update([
                'status'             => 'approved',
                'reviewed_by'        => $reviewerId,
                'reviewed_at'        => now(),
                'amended_invoice_id' => $amendedInvoice->id,
            ]);

            // 7. Audit logs
            $this->auditService->log(
                action: 'invoice.amendment_approved',
                auditable: $amendmentReq,
                newValues: [
                    'request_id'             => $amendmentReq->id,
                    'previous_invoice'       => $originalInvoice->invoice_number,
                    'amended_invoice_number' => $amendedInvoice->invoice_number,
                    'new_total'              => $newGrandTotal,
                ],
                companyId: $originalInvoice->company_id,
                userId: $reviewerId
            );

            return $amendedInvoice->load(['items', 'buyer', 'order']);
        });
    }

    /**
     * Reject an invoice amendment request with reason. Original invoice remains unchanged.
     *
     * @param int $requestId
     * @param string $rejectionReason
     * @param int $reviewerId
     * @param int|null $companyId
     * @return InvoiceAmendmentRequest
     * @throws ValidationException
     */
    public function rejectAmendment(int $requestId, string $rejectionReason, int $reviewerId, ?int $companyId = null): InvoiceAmendmentRequest
    {
        return DB::transaction(function () use ($requestId, $rejectionReason, $reviewerId, $companyId) {
            $query = InvoiceAmendmentRequest::where('id', $requestId)->lockForUpdate();

            $amendmentReq = $query->first();

            if (!$amendmentReq) {
                throw ValidationException::withMessages([
                    'request_id' => ['Amendment request not found.'],
                ]);
            }

            if ($amendmentReq->status !== 'pending') {
                throw ValidationException::withMessages([
                    'request_id' => ["Amendment request is already '{$amendmentReq->status}'."],
                ]);
            }

            $amendmentReq->update([
                'status'           => 'rejected',
                'rejection_reason' => $rejectionReason,
                'reviewed_by'      => $reviewerId,
                'reviewed_at'      => now(),
            ]);

            $this->auditService->log(
                action: 'invoice.amendment_rejected',
                auditable: $amendmentReq,
                newValues: [
                    'request_id'       => $amendmentReq->id,
                    'invoice_id'       => $amendmentReq->invoice_id,
                    'rejection_reason' => $rejectionReason,
                ],
                companyId: $companyId,
                userId: $reviewerId
            );

            return $amendmentReq->load(['invoice', 'requester', 'reviewer']);
        });
    }

    /**
     * List amendment requests.
     */
    public function listAmendmentRequests(?string $status = null, ?int $companyId = null)
    {
        $query = InvoiceAmendmentRequest::with(['invoice', 'requester', 'reviewer', 'amendedInvoice'])
            ->latest('id');

        if ($status) {
            $query->where('status', $status);
        }

        if ($companyId) {
            $query->whereHas('invoice', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        return $query->paginate(50);
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Buyer;
use App\Models\Order;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Create an Invoice record with line item snapshots and server-side calculated totals.
     *
     * @param array $data
     * @param int|null $companyId
     * @return Invoice
     * @throws ValidationException
     */
    public function createInvoice(array $data, ?int $companyId = null): Invoice
    {
        return DB::transaction(function () use ($data, $companyId) {
            $targetCompanyId = $companyId ?? ($data['company_id'] ?? auth()->user()?->company_id);

            if (!$targetCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company ID is required to generate an invoice.'],
                ]);
            }

            // Validate buyer belongs to the company
            if (!empty($data['buyer_id'])) {
                $buyer = Buyer::where('id', $data['buyer_id'])->first();
                if (!$buyer || ($buyer->company_id && $buyer->company_id != $targetCompanyId)) {
                    throw ValidationException::withMessages([
                        'buyer_id' => ['Buyer not found or belongs to another company.'],
                    ]);
                }
            }

            // Calculate totals strictly on server side using decimal precision
            $items = $data['items'] ?? [];
            if (empty($items)) {
                throw ValidationException::withMessages([
                    'items' => ['Invoice must contain at least one line item.'],
                ]);
            }

            $calculatedSubtotal = 0.00;
            $lineSnapshots = [];

            foreach ($items as $item) {
                $qty = (int) ($item['quantity'] ?? 1);
                $unitPrice = round((float) ($item['unit_price'] ?? 0.00), 2);
                $lineTotal = round($qty * $unitPrice, 2);

                if ($qty <= 0 || $unitPrice < 0) {
                    throw ValidationException::withMessages([
                        'items' => ['Invalid quantity or unit price for invoice line item.'],
                    ]);
                }

                $calculatedSubtotal = round($calculatedSubtotal + $lineTotal, 2);

                $lineSnapshots[] = [
                    'bale_id'          => $item['bale_id'] ?? null,
                    'container_id'     => $item['container_id'] ?? null,
                    'product_id'       => $item['product_id'] ?? null,
                    'item_category_id' => $item['item_category_id'] ?? null,
                    'item_description' => $item['item_description'] ?? 'Garment Bale',
                    'quantity'         => $qty,
                    'unit_price'       => $unitPrice,
                    'total_price'      => $lineTotal,
                ];
            }

            $taxAmount = round((float) ($data['tax_amount'] ?? 0.00), 2);
            $discountAmount = round((float) ($data['discount_amount'] ?? 0.00), 2);
            $totalAmount = round($calculatedSubtotal + $taxAmount - $discountAmount, 2);

            $authUser = auth()->user();
            $isStaffUser = $authUser instanceof \App\Models\User;

            $invoice = Invoice::create([
                'order_id'        => $data['order_id'] ?? null,
                'buyer_id'        => $data['buyer_id'] ?? null,
                'company_id'      => $targetCompanyId,
                'client_id'       => $data['client_id'] ?? ($authUser instanceof \App\Models\Client ? $authUser->id : null),
                'subtotal'        => $calculatedSubtotal,
                'tax_amount'      => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount'    => $totalAmount,
                'paid_amount'     => 0.00,
                'payment_status'  => 'unpaid',
                'status'          => $data['status'] ?? 'draft',
                'issued_date'     => $data['issued_date'] ?? now()->toDateString(),
                'due_date'        => $data['due_date'] ?? now()->toDateString(),
                'notes'           => $data['notes'] ?? null,
                'created_by'      => $isStaffUser ? $authUser->id : null,
            ]);

            foreach ($lineSnapshots as $line) {
                $line['invoice_id'] = $invoice->id;
                InvoiceItem::create($line);
            }

            $this->auditService->logCreate($invoice, 'invoice.create', $targetCompanyId);

            return $invoice->load(['items', 'buyer', 'order']);
        });
    }

    /**
     * Atomically finalize an invoice, locking it from ordinary mutations.
     *
     * @param int|Invoice $invoice
     * @param int|null $staffId
     * @param int|null $companyId
     * @return Invoice
     * @throws ValidationException
     */
    public function finalizeInvoice($invoice, ?int $staffId = null, ?int $companyId = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $staffId, $companyId) {
            $invoiceId = $invoice instanceof Invoice ? $invoice->id : (int) $invoice;

            $query = Invoice::where('id', $invoiceId)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $inv = $query->first();

            if (!$inv) {
                throw ValidationException::withMessages([
                    'invoice' => ['Invoice not found or unauthorized.'],
                ]);
            }

            if ($inv->status === 'finalized') {
                return $inv; // Idempotent
            }

            if ($inv->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'invoice' => ["Cancelled invoice {$inv->invoice_number} cannot be finalized."],
                ]);
            }

            $inv->update([
                'status'      => 'finalized',
                'approved_by' => $staffId ?? auth()->id(),
            ]);

            $this->auditService->log(
                action: 'invoice.finalize',
                auditable: $inv,
                newValues: ['status' => 'finalized', 'invoice_number' => $inv->invoice_number],
                companyId: $inv->company_id,
                userId: $staffId ?? auth()->id()
            );

            return $inv->load(['items', 'buyer', 'order']);
        });
    }

    /**
     * Attempt to update a draft invoice. Finalized invoices are strictly IMMUTABLE.
     *
     * @param int $invoiceId
     * @param array $data
     * @param int|null $companyId
     * @return Invoice
     * @throws ValidationException
     */
    public function updateDraftInvoice(int $invoiceId, array $data, ?int $companyId = null): Invoice
    {
        return DB::transaction(function () use ($invoiceId, $data, $companyId) {
            $query = Invoice::where('id', $invoiceId)->lockForUpdate();

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            $inv = $query->first();

            if (!$inv) {
                throw ValidationException::withMessages([
                    'invoice' => ['Invoice not found or unauthorized.'],
                ]);
            }

            // STRICT IMMUTABILITY ENFORCEMENT
            if ($inv->status === 'finalized') {
                throw ValidationException::withMessages([
                    'invoice' => ["Finalized invoice {$inv->invoice_number} is immutable. Changes must go through the invoice amendment workflow."],
                ]);
            }

            $oldValues = $inv->toArray();
            $inv->update($data);

            $this->auditService->logUpdate($inv, 'invoice.update_draft', $oldValues, $inv->toArray(), $inv->company_id);

            return $inv;
        });
    }

    /**
     * Retrieve an invoice by invoice_number with company isolation.
     */
    public function getInvoiceByNumber(string $invoiceNumber, ?int $companyId = null): ?Invoice
    {
        $query = Invoice::where('invoice_number', $invoiceNumber)->with(['items', 'buyer', 'order', 'company']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->first();
    }
}

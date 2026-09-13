<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Customer;
use App\Models\BaleBatch;
use App\Models\Item_category;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected InvoiceService $invoiceService,
        protected AuditService $auditService
    ) {
    }

    /**
     * Complete sale workflow: customer resolution -> quantity allocation from
     * batches (FIFO by arrival date, oldest containers first) -> order ->
     * finalized invoice with per-batch line snapshots -> pickup code.
     *
     * Expected $data:
     *  - customer_id OR customer_uuid OR customer_whatsapp
     *  - items: [ { item_category_id, quantity, unit_price, item_description? } ]
     *  - optional: tax_amount, discount_amount, payment_method, notes, currency
     */
    public function createSale(array $data, ?int $companyId = null): array
    {
        return DB::transaction(function () use ($data, $companyId) {
            // 1. Resolve the customer (single identity shared with WhatsApp chat)
            $customer = null;
            if (!empty($data['customer_id'])) {
                $customer = Customer::find($data['customer_id']);
            } elseif (!empty($data['customer_uuid'])) {
                $customer = Customer::where('uuid', $data['customer_uuid'])->first();
            } elseif (!empty($data['customer_whatsapp'])) {
                $customer = Customer::where('whatsapp_number', $data['customer_whatsapp'])->first();
            }

            if (!$customer) {
                throw ValidationException::withMessages([
                    'customer' => ['A registered customer is required. Register the buyer first.'],
                ]);
            }

            // 2. Allocate quantities from batches
            $items = $data['items'] ?? [];
            if (empty($items)) {
                throw ValidationException::withMessages([
                    'items' => ['Sale must include at least one line.'],
                ]);
            }

            $allocationPlan = [];
            $lineItemsData = [];
            $totalQuantity = 0;
            $calculatedTotal = 0.00;

            foreach ($items as $item) {
                if (empty($item['item_category_id']) || empty($item['quantity'])) {
                    throw ValidationException::withMessages([
                        'items' => ['Each line needs item_category_id and quantity.'],
                    ]);
                }

                $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
                if ($unitPrice <= 0) {
                    throw ValidationException::withMessages([
                        'items' => ['Each sale line requires a unit_price greater than zero.'],
                    ]);
                }

                $categoryId = (int) $item['item_category_id'];
                $requested = (int) $item['quantity'];

                // FIFO: oldest arrivals first, spanning containers if needed.
                $batches = BaleBatch::where('category_id', $categoryId)
                    ->where('qty_available', '>', 0)
                    ->orderBy('arrival_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $availableTotal = $batches->sum('qty_available');
                if ($availableTotal < $requested) {
                    $catName = Item_category::find($categoryId)?->category_name ?? "Category #{$categoryId}";
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for {$catName}. Requested: {$requested}, Available: {$availableTotal}."],
                    ]);
                }

                $remaining = $requested;
                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, $batch->qty_available);
                    $remaining -= $take;

                    $allocationPlan[] = ['batch' => $batch, 'quantity' => $take];

                    $lineTotal = round($take * $unitPrice, 2);
                    $lineItemsData[] = [
                        'bale_batch_id'    => $batch->id,
                        'container_id'     => $batch->container_id,
                        'item_category_id' => $categoryId,
                        'item_description' => $item['item_description']
                            ?? ($batch->category?->category_name ?? 'Garment Bale'),
                        'quantity'         => $take,
                        'unit_price'       => $unitPrice,
                        'total_price'      => $lineTotal,
                    ];
                    $calculatedTotal = round($calculatedTotal + $lineTotal, 2);
                    $totalQuantity += $take;
                }
            }

            $authUser = auth()->user();
            $isStaffUser = $authUser instanceof \App\Models\User;
            $createdById = $isStaffUser ? $authUser->id : null;

            // 3. Unique pickup code
            $pickupCode = $this->generatePickupCode();

            // 4. Order record
            $order = Order::create([
                'customer_id'       => $customer->id,
                'customer_name'     => $customer->name,
                'company_id'        => $companyId,
                'salesperson_id'    => $createdById,
                'order_quantity'    => $totalQuantity,
                'total_amount'      => $calculatedTotal,
                'order_amount'      => $calculatedTotal,
                'currency'          => $data['currency'] ?? 'GHS',
                'status'            => 'pending',
                'payment_status'    => 'pending',
                'pickup_code'       => $pickupCode,
                'pickup_status'     => 'pending',
                'payment_method'    => $data['payment_method'] ?? 'cash',
                'payment_reference' => $data['payment_reference'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $createdById,
            ]);

            // 5. Move quantities available -> sold + stock movements
            $this->inventoryService->consumeFromBatches(
                $allocationPlan,
                $order->id,
                $createdById
            );

            // 6. Finalized invoice with per-batch line snapshots
            $invoice = $this->invoiceService->createInvoice([
                'order_id'        => $order->id,
                'customer_id'     => $customer->id,
                'company_id'      => $companyId,
                'tax_amount'      => $data['tax_amount'] ?? 0.00,
                'discount_amount' => $data['discount_amount'] ?? 0.00,
                'items'           => $lineItemsData,
                'status'          => 'finalized',
                'notes'           => $order->notes,
            ], $companyId);

            $order->update([
                'invoice_code' => $invoice->invoice_number,
            ]);

            // 7. Audit trail
            $this->auditService->log(
                action: 'sale.create',
                auditable: $order,
                newValues: [
                    'order_no'       => $order->order_no,
                    'invoice_number' => $invoice->invoice_number,
                    'buyer_id'       => $customer->buyer_id,
                    'pickup_code'    => $order->pickup_code,
                    'total_amount'   => $order->total_amount,
                    'total_quantity' => $totalQuantity,
                ],
                companyId: $companyId,
                userId: $createdById
            );

            return [
                'order'       => $order->load(['customer', 'invoice.items']),
                'invoice'     => $invoice,
                'pickup_code' => $order->pickup_code,
            ];
        });
    }

    /**
     * Release goods against a pickup code: "Confirm #PKP-XXXXXX released".
     * Requires the invoice to be paid; moves quantities sold -> released and
     * logs the releasing staff identity. Pickup codes never expire.
     */
    public function confirmPickup(string $pickupCode, ?int $staffId = null): Order
    {
        $order = DB::transaction(function () use ($pickupCode, $staffId) {
            $order = Order::where('pickup_code', $pickupCode)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw ValidationException::withMessages([
                    'pickup' => ["No order found for pickup code {$pickupCode}."],
                ]);
            }

            if ($order->pickup_status === 'released') {
                throw ValidationException::withMessages([
                    'pickup' => ["Order {$order->order_no} was already released."],
                ]);
            }

            if ($order->payment_status !== 'paid') {
                throw ValidationException::withMessages([
                    'pickup' => ["Payment for order {$order->order_no} is '{$order->payment_status}'. Goods are released only after the cashier confirms payment."],
                ]);
            }

            $lines = $order->invoice?->items
                ?->filter(fn ($item) => $item->bale_batch_id)
                ->map(fn ($item) => [
                    'bale_batch_id' => $item->bale_batch_id,
                    'quantity'      => $item->quantity,
                ])
                ->values()
                ->all() ?? [];

            if (empty($lines)) {
                throw ValidationException::withMessages([
                    'pickup' => ["Order {$order->order_no} has no stock lines linked to its invoice."],
                ]);
            }

            $this->inventoryService->releaseFromBatches($lines, $order->id, $staffId ?? auth()->id());

            $order->update([
                'pickup_status' => 'released',
                'status'        => 'completed',
            ]);

            $this->auditService->log(
                action: 'sale.release',
                auditable: $order,
                newValues: [
                    'pickup_code' => $pickupCode,
                    'released_by' => $staffId ?? auth()->id(),
                    'quantity'    => array_sum(array_column($lines, 'quantity')),
                ],
                companyId: $order->company_id,
                userId: $staffId ?? auth()->id()
            );

            return $order->fresh(['customer', 'invoice.items']);
        });

        $this->notifyReleased($order);

        return $order;
    }

    /**
     * Final WhatsApp confirmation once goods leave the warehouse - queued so
     * the release API never waits on the gateway.
     */
    private function notifyReleased(Order $order): void
    {
        $customer = $order->customer;
        $chatId = $customer?->wa_id ?? $customer?->whatsapp_number;

        if (!$chatId) {
            return;
        }

        $quantity = (int) $order->order_quantity;

        \App\Jobs\SendWhatsAppText::dispatch(
            $chatId,
            "Your goods have been released ✅\n\n"
            . "Order: {$order->order_no}\n"
            . "Invoice: {$order->invoice_code}\n"
            . "Items: {$quantity} bale" . ($quantity === 1 ? '' : 's') . "\n\n"
            . "Thank you for shopping with Orvell 🙏 We look forward to serving you again.",
            "release:{$order->order_no}"
        );
    }

    /**
     * Find order by unique pickup code.
     */
    public function getSaleByPickupCode(string $pickupCode): ?Order
    {
        return Order::where('pickup_code', $pickupCode)
            ->with(['customer', 'invoice.items'])
            ->first();
    }

    private function generatePickupCode(): string
    {
        do {
            $code = 'PKP-' . strtoupper(Str::random(6));
        } while (Order::where('pickup_code', $code)->exists());

        return $code;
    }
}

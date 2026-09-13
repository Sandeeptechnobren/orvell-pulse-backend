<?php

namespace App\Services;

use App\Models\BaleBatch;
use App\Models\Customer;
use App\Models\Item_category;
use App\Models\OrderRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderRequestService
{
    public function __construct(
        protected SaleService $saleService,
        protected AuditService $auditService
    ) {
    }

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = OrderRequest::query()->with(['customer', 'items.category', 'order']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('request_no', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('buyer_id', 'like', "%{$search}%");
                    });
            });
        }

        return $query
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getByUuid(string $uuid): OrderRequest
    {
        $request = OrderRequest::where('uuid', $uuid)
            ->with(['customer', 'items.category', 'order.invoice', 'actionedBy'])
            ->first();

        if (!$request) {
            throw new ModelNotFoundException('Order request not found.');
        }

        return $request;
    }

    /**
     * Create a request from the WhatsApp agent. Lines: [['category_id', 'quantity'], ...].
     * Availability is reported, never reserved.
     */
    public function createFromChat(Customer $customer, array $lines, ?string $note = null): array
    {
        return DB::transaction(function () use ($customer, $lines, $note) {
            $validLines = [];
            $availability = [];

            foreach ($lines as $line) {
                $categoryId = (int) ($line['category_id'] ?? 0);
                $qty = (int) ($line['quantity'] ?? 0);

                if ($categoryId <= 0 || $qty <= 0) {
                    continue;
                }

                $category = Item_category::find($categoryId);
                if (!$category) {
                    continue;
                }

                $available = (int) BaleBatch::where('category_id', $categoryId)
                    ->sum('qty_available');

                $validLines[] = ['category_id' => $categoryId, 'quantity' => $qty];
                $availability[] = [
                    'category' => $category->category_name,
                    'requested' => $qty,
                    'available_now' => $available,
                ];
            }

            if (empty($validLines)) {
                throw ValidationException::withMessages([
                    'lines' => ['A request needs at least one valid category and quantity.'],
                ]);
            }

            $request = OrderRequest::create([
                'customer_id' => $customer->id,
                'status' => 'pending',
                'notes' => $note,
            ]);

            foreach ($validLines as $line) {
                $request->items()->create($line);
            }

            $this->auditService->log(
                action: 'order_request.create',
                auditable: $request,
                newValues: [
                    'request_no' => $request->request_no,
                    'buyer_id' => $customer->buyer_id,
                    'lines' => $validLines,
                ]
            );

            return [
                'request_no' => $request->request_no,
                'status' => 'pending',
                'lines' => $availability,
            ];
        });
    }

    public function listForCustomer(Customer $customer, int $limit = 5): array
    {
        return OrderRequest::where('customer_id', $customer->id)
            ->with(['items.category', 'order.invoice'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (OrderRequest $request) {
                $invoice = $request->order?->invoice;

                // For converted requests, report the CONFIRMED quantities from
                // the invoice (staff may have approved less than requested).
                if ($request->status === 'converted' && $invoice) {
                    $lines = collect($invoice->items)
                        ->groupBy('item_category_id')
                        ->map(fn ($group) => [
                            'category' => $group->first()->item_description,
                            'quantity' => (int) $group->sum('quantity'),
                        ])
                        ->values()
                        ->all();
                } else {
                    $lines = $request->items->map(fn ($item) => [
                        'category' => $item->category?->category_name,
                        'quantity' => $item->quantity,
                    ])->all();
                }

                return [
                    'request_no' => $request->request_no,
                    'status' => $request->status,
                    'requested_at' => $request->created_at?->toDateTimeString(),
                    'lines' => $lines,
                    'declined_reason' => $request->declined_reason,
                    'invoice_number' => $invoice?->invoice_number,
                    'invoice_total' => $invoice?->total_amount,
                    'payment_status' => $invoice?->payment_status,
                    'pickup_code' => $invoice && $invoice->payment_status === 'paid'
                        ? $request->order?->pickup_code
                        : null,
                ];
            })
            ->all();
    }

    public function cancelForCustomer(Customer $customer, string $requestNo): OrderRequest
    {
        return DB::transaction(function () use ($customer, $requestNo) {
            $request = OrderRequest::where('request_no', $requestNo)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (!$request) {
                throw new ModelNotFoundException("Request {$requestNo} was not found for this customer.");
            }

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages([
                    'request' => ["Request {$requestNo} is '{$request->status}' and can no longer be cancelled. Contact the team for changes."],
                ]);
            }

            $request->update(['status' => 'cancelled', 'actioned_at' => now()]);

            $this->auditService->log(
                action: 'order_request.cancel',
                auditable: $request,
                newValues: ['request_no' => $request->request_no, 'cancelled_by' => 'customer']
            );

            return $request;
        });
    }

    /**
     * Staff confirm: price the lines and convert into a real sale via
     * SaleService (stock reserved, invoice generated, pickup code issued).
     * Lines: [['item_category_id', 'quantity', 'unit_price'], ...].
     */
    public function convert(string $uuid, array $lines, ?int $staffId = null, ?string $notes = null): OrderRequest
    {
        $request = DB::transaction(function () use ($uuid, $lines, $staffId, $notes) {
            $request = OrderRequest::where('uuid', $uuid)->lockForUpdate()->first();

            if (!$request) {
                throw new ModelNotFoundException('Order request not found.');
            }

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages([
                    'request' => ["Request {$request->request_no} is already '{$request->status}'."],
                ]);
            }

            $sale = $this->saleService->createSale([
                'customer_id' => $request->customer_id,
                'items' => $lines,
                'notes' => $notes ?? "From WhatsApp request {$request->request_no}",
            ]);

            $request->update([
                'status' => 'converted',
                'actioned_by' => $staffId,
                'actioned_at' => now(),
                'order_id' => $sale['order']->id,
            ]);

            $this->auditService->log(
                action: 'order_request.convert',
                auditable: $request,
                newValues: [
                    'request_no' => $request->request_no,
                    'invoice_number' => $sale['invoice']->invoice_number,
                    'total_amount' => $sale['invoice']->total_amount,
                ],
                userId: $staffId
            );

            return $request->fresh(['customer', 'items.category', 'order.invoice']);
        });

        $invoice = $request->order?->invoice;

        // Requested quantities per category, to call out any staff adjustment.
        $requested = [];
        foreach ($request->items as $item) {
            $requested[$item->category_id] = [
                'name' => $item->category?->category_name ?? 'Item',
                'quantity' => $item->quantity,
            ];
        }

        // Confirmed quantities: invoice lines grouped by category (FIFO can
        // split one category across several batch lines).
        $confirmed = [];
        foreach ($invoice?->items ?? [] as $line) {
            $key = $line->item_category_id ?? 0;
            if (!isset($confirmed[$key])) {
                $confirmed[$key] = [
                    'name' => $line->item_description,
                    'quantity' => 0,
                ];
            }
            $confirmed[$key]['quantity'] += (int) $line->quantity;
        }

        $lineTexts = [];
        foreach ($confirmed as $categoryId => $line) {
            $text = "• {$line['quantity']} × {$line['name']}";
            $requestedQty = $requested[$categoryId]['quantity'] ?? null;
            if ($requestedQty !== null && $requestedQty !== $line['quantity']) {
                $text .= " (you requested {$requestedQty})";
            }
            $lineTexts[] = $text;
        }
        foreach ($requested as $categoryId => $line) {
            if (!isset($confirmed[$categoryId])) {
                $lineTexts[] = "• 0 × {$line['name']} (you requested {$line['quantity']} - not available this time)";
            }
        }

        $this->notifyCustomer(
            $request,
            "Good news! Your order {$request->request_no} is confirmed ✅\n\n"
            . "Confirmed items:\n" . implode("\n", $lineTexts) . "\n\n"
            . "Invoice: {$invoice?->invoice_number}\n"
            . "Total: GHS " . number_format((float) ($invoice?->total_amount ?? 0), 2) . "\n\n"
            . "Please arrange payment - once the cashier confirms it, you will receive your pickup code."
        );

        return $request;
    }

    public function decline(string $uuid, string $reason, ?int $staffId = null): OrderRequest
    {
        $request = DB::transaction(function () use ($uuid, $reason, $staffId) {
            $request = OrderRequest::where('uuid', $uuid)->lockForUpdate()->first();

            if (!$request) {
                throw new ModelNotFoundException('Order request not found.');
            }

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages([
                    'request' => ["Request {$request->request_no} is already '{$request->status}'."],
                ]);
            }

            $request->update([
                'status' => 'declined',
                'declined_reason' => $reason,
                'actioned_by' => $staffId,
                'actioned_at' => now(),
            ]);

            $this->auditService->log(
                action: 'order_request.decline',
                auditable: $request,
                newValues: ['request_no' => $request->request_no, 'reason' => $reason],
                userId: $staffId
            );

            return $request->fresh(['customer', 'items.category']);
        });

        $this->notifyCustomer(
            $request,
            "About your order {$request->request_no}: unfortunately we could not confirm it this time.\n\n"
            . "Reason: {$reason}\n\n"
            . "Feel free to send a new request anytime 🙏"
        );

        return $request;
    }

    /**
     * Outbound WhatsApp notice via the queue - the API never waits on the
     * gateway, and delivery retries happen in the background.
     */
    private function notifyCustomer(OrderRequest $request, string $message): void
    {
        $chatId = $request->customer?->wa_id ?? $request->customer?->whatsapp_number;

        if (!$chatId) {
            Log::warning('Order request notification skipped - no WhatsApp id', [
                'request_no' => $request->request_no,
            ]);
            return;
        }

        \App\Jobs\SendWhatsAppText::dispatch($chatId, $message, "order_request:{$request->request_no}");
    }
}
